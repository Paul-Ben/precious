<?php

namespace Tests\Feature\Payments;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use App\Models\Receipt;
use App\Models\Reservation;
use App\Models\WebhookEvent;
use App\Notifications\PaymentReceiptNotification;
use App\Support\Money;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    /** What the fake Paystack reports on verify: success | failed | abandoned | short. */
    private string $paystackOutcome = 'success';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->enableGateway('paystack');

        Http::fake([
            'api.paystack.co/transaction/initialize' => fn (HttpRequest $r) => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/'.$r['reference'],
                    'access_code' => 'ac_'.$r['reference'],
                    'reference' => $r['reference'],
                ],
            ]),
            'api.paystack.co/transaction/verify/*' => function (HttpRequest $r) {
                $reference = rawurldecode(basename(parse_url($r->url(), PHP_URL_PATH)));
                $payment = Payment::where('reference', $reference)->firstOrFail();
                $amount = Money::toMinor($payment->charged_amount);

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => [
                    'id' => 4099260516,
                    'status' => $this->paystackOutcome === 'short' ? 'success' : $this->paystackOutcome,
                    'reference' => $reference,
                    'amount' => $this->paystackOutcome === 'short' ? $amount - 100_00 : $amount,
                    'currency' => 'NGN',
                    'fees' => 1234_00,
                    'channel' => 'card',
                    'paid_at' => now()->toIso8601String(),
                    'gateway_response' => $this->paystackOutcome === 'failed' ? 'Declined' : 'Successful',
                ]]);
            },
        ]);
    }

    private function startPayment(Reservation $reservation, string $token, string $option = 'deposit'): Payment
    {
        $reference = $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", [
            'token' => $token,
            'option' => $option,
        ])->assertCreated()->json('data.reference');

        return Payment::where('reference', $reference)->firstOrFail();
    }

    private function signedWebhook(array $payload, string $secret = 'sk_test_secret'): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/payments/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
        ], $body);
    }

    #[Test]
    public function payment_options_show_the_deposit_and_full_amount_with_the_processing_fee(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));

        // 3 nights × ₦50,000 + 7.5% VAT = ₦161,250; 30% deposit = ₦48,375.
        $this->getJson("/api/v1/public/reservations/{$reservation->number}/payment-options?token={$token}")
            ->assertOk()
            ->assertJsonPath('data.payable', true)
            ->assertJsonPath('data.fees_passed_to_customer', true)
            ->assertJsonPath('data.options.0.option', 'deposit')
            ->assertJsonPath('data.options.0.amount', '48375.00')
            ->assertJsonPath('data.options.0.by_gateway.0.gateway', 'paystack')
            ->assertJsonPath('data.options.0.by_gateway.0.fee', '838.20')
            ->assertJsonPath('data.options.0.by_gateway.0.total', '49213.20')
            ->assertJsonPath('data.options.1.option', 'balance')
            ->assertJsonPath('data.options.1.amount', '161250.00')
            ->assertJsonPath('data.options.1.by_gateway.0.fee', '2000.00');

        $this->getJson("/api/v1/public/reservations/{$reservation->number}/payment-options?token=".str_repeat('x', 40))->assertNotFound();
    }

    #[Test]
    public function the_hotel_can_choose_to_absorb_gateway_fees(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $this->actingAsUser($this->staff('Administrator'))
            ->patchJson('/api/v1/property', ['policies' => ['pass_gateway_fees_to_customer' => false]])->assertOk();
        $this->withoutBearer();

        $payment = $this->startPayment($reservation, $token);

        $this->assertSame('0.00', $payment->customer_fee);
        $this->assertSame($payment->amount, $payment->charged_amount);
    }

    #[Test]
    public function starting_a_payment_creates_a_pending_payment_and_a_paystack_checkout(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $reservation->forceFill(['expires_at' => now()->addMinutes(3)])->save();

        $response = $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", ['token' => $token, 'option' => 'deposit'])
            ->assertCreated()
            ->assertJsonPath('data.gateway', 'paystack')
            ->assertJsonPath('data.amount', '48375.00')
            ->assertJsonPath('data.customer_fee', '838.20')
            ->assertJsonPath('data.charged_amount', '49213.20');

        $this->assertStringStartsWith('https://checkout.paystack.com/PAY-', $response->json('data.authorization_url'));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize')
            && $r->hasHeader('Authorization', 'Bearer sk_test_secret')
            && $r['amount'] === 4_921_320
            && $r['email'] === 'john.doe@example.com'
            && $r['callback_url'] === config('security.frontend_url').'/pay/callback');

        $payment = Payment::firstOrFail();
        $this->assertSame('PENDING', $payment->status->value);
        $this->assertSame('test', $payment->gateway_mode->value);

        // The hold is stretched so the guest is not cut off mid-payment.
        $this->assertGreaterThanOrEqual(now()->addMinutes(14)->timestamp, $reservation->refresh()->expires_at->timestamp);
        $this->assertTrue(AuditLog::where('action', 'payments.initiated')->exists());
    }

    #[Test]
    public function no_enabled_gateway_means_no_online_payment(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        PaymentGatewaySetting::query()->update(['is_enabled' => false, 'is_default' => false]);

        $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", ['token' => $token, 'option' => 'deposit'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_PAYMENT_GATEWAY');
    }

    #[Test]
    public function a_verified_deposit_confirms_the_reservation_and_issues_a_receipt(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $payment = $this->startPayment($reservation, $token);

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])
            ->assertOk()
            ->assertJsonPath('data.status', 'SUCCESSFUL')
            ->assertJsonPath('data.reservation.status', 'CONFIRMED')
            ->assertJsonPath('data.reservation.payment_status', 'DEPOSIT_PAID')
            ->assertJsonPath('data.reservation.balance', '112875.00');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame('48375.00', $reservation->amount_paid);
        $this->assertNull($reservation->expires_at);

        $receipt = Receipt::firstOrFail();
        $this->assertMatchesRegularExpression('/^RCP-\d{4}-000001$/', $receipt->number);
        $this->assertSame('838.20', $receipt->snapshot['processing_fee']);
        $this->assertSame('112875.00', $receipt->snapshot['balance_after']);

        $payment->refresh();
        $this->assertSame('1234.00', $payment->gateway_fee);
        $this->assertSame('card', $payment->channel);

        Notification::assertSentOnDemand(PaymentReceiptNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'john.doe@example.com');

        // The guest sees the payment on their booking.
        $this->getJson("/api/v1/public/reservations/{$reservation->number}?token={$token}")
            ->assertJsonPath('data.payments.0.receipt_number', $receipt->number)
            ->assertJsonMissingPath('data.payments.0.payer_email');
    }

    #[Test]
    public function redirect_webhook_and_reconciliation_credit_a_payment_only_once(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $payment = $this->startPayment($reservation, $token, 'balance');

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])->assertOk();
        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])->assertOk();

        $this->signedWebhook(['event' => 'charge.success', 'data' => ['id' => 1, 'reference' => $payment->reference]])
            ->assertOk()->assertJsonPath('data.outcome', 'SUCCESSFUL');

        $this->travel(10)->minutes();
        Artisan::call('payments:reconcile');

        $this->assertSame('161250.00', $reservation->refresh()->amount_paid);
        $this->assertSame('PAID', $reservation->payment_status->value);
        $this->assertSame(1, Receipt::count());
    }

    #[Test]
    public function webhooks_are_verified_and_deduplicated(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $payment = $this->startPayment($reservation, $token);
        $payload = ['event' => 'charge.success', 'data' => ['id' => 77, 'reference' => $payment->reference]];

        // Wrong signature: rejected, nothing credited.
        $this->signedWebhook($payload, 'sk_test_wrong')->assertStatus(401);
        $this->assertSame('PENDING', $payment->refresh()->status->value);

        $this->signedWebhook($payload)->assertOk()->assertJsonPath('data.outcome', 'SUCCESSFUL');
        $this->signedWebhook($payload)->assertOk()->assertJsonPath('data.outcome', 'duplicate');

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, Receipt::count());

        // Events we don't use are acknowledged and ignored.
        $this->signedWebhook(['event' => 'transfer.success', 'data' => ['id' => 5]])->assertOk()->assertJsonPath('data.outcome', 'ignored');
    }

    #[Test]
    public function a_webhook_payload_is_never_trusted_without_asking_the_gateway(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $payment = $this->startPayment($reservation, $token);
        $this->paystackOutcome = 'abandoned';

        // Correctly signed, claims success - but Paystack's API says not paid yet.
        $this->signedWebhook(['event' => 'charge.success', 'data' => ['id' => 9, 'reference' => $payment->reference, 'amount' => 999999999]])
            ->assertOk()->assertJsonPath('data.outcome', 'PENDING');

        $this->assertSame('0.00', $reservation->refresh()->amount_paid);
    }

    #[Test]
    public function a_declined_payment_is_marked_failed(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $payment = $this->startPayment($reservation, $token);
        $this->paystackOutcome = 'failed';

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure_reason', 'Declined')
            ->assertJsonPath('data.reservation.status', 'PENDING_PAYMENT');
    }

    #[Test]
    public function a_payment_for_less_than_was_asked_is_not_credited(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $payment = $this->startPayment($reservation, $token);
        $this->paystackOutcome = 'short';

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])->assertJsonPath('data.status', 'FAILED');

        $payment->refresh();
        $this->assertTrue($payment->needs_attention);
        $this->assertSame('AMOUNT_MISMATCH', $payment->attention_reason);
        $this->assertSame('0.00', $reservation->refresh()->amount_paid);
    }

    #[Test]
    public function a_late_payment_revives_the_booking_when_the_room_is_still_free(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00', ['201']));
        $payment = $this->startPayment($reservation, $token);

        $this->travel(31)->minutes();
        Artisan::call('reservations:expire-holds');
        $this->assertSame(ReservationStatus::Expired, $reservation->refresh()->status);

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])
            ->assertJsonPath('data.status', 'SUCCESSFUL')
            ->assertJsonPath('data.reservation.status', 'CONFIRMED');

        $this->assertTrue($reservation->rooms()->first()->is_active);
        $this->assertFalse($payment->refresh()->needs_attention);
    }

    #[Test]
    public function a_late_payment_is_flagged_when_the_room_has_been_resold(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201']);
        [$reservation, $token] = $this->bookOnline($type);
        $payment = $this->startPayment($reservation, $token);

        $this->travel(31)->minutes();
        Artisan::call('reservations:expire-holds');
        $this->bookOnline($type, overrides: ['guest' => ['email' => 'second@example.com']]);

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $payment->reference])
            ->assertJsonPath('data.status', 'SUCCESSFUL')
            ->assertJsonPath('data.reservation.status', 'EXPIRED');

        $payment->refresh();
        $this->assertTrue($payment->needs_attention);
        $this->assertSame('ROOM_NO_LONGER_AVAILABLE', $payment->attention_reason);
        // The money is on record so it can be refunded.
        $this->assertSame('48375.00', $reservation->refresh()->amount_paid);
    }

    #[Test]
    public function expired_or_cancelled_reservations_cannot_start_a_payment(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $this->travel(31)->minutes();

        $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", ['token' => $token, 'option' => 'deposit'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'NOT_PAYABLE');

        $this->getJson("/api/v1/public/reservations/{$reservation->number}/payment-options?token={$token}")
            ->assertJsonPath('data.payable', false);
    }

    #[Test]
    public function reconciliation_abandons_payments_that_never_completed(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        $payment = $this->startPayment($reservation, $token);
        $this->paystackOutcome = 'abandoned';

        $this->travel(10)->minutes();
        Artisan::call('payments:reconcile');
        $this->assertSame('PENDING', $payment->refresh()->status->value);
        $this->assertSame(1, $payment->verify_attempts);

        $this->travel(2)->days();
        Artisan::call('payments:reconcile');
        $this->assertSame('ABANDONED', $payment->refresh()->status->value);
    }

    #[Test]
    public function a_customer_can_pay_their_own_reservation_only(): void
    {
        $customer = $this->customer();
        $type = $this->roomType();

        $this->actingAsUser($customer);
        [$reservation] = $this->bookOnline($type);

        $this->getJson("/api/v1/me/reservations/{$reservation->id}/payment-options")->assertOk()->assertJsonPath('data.payable', true);
        $this->postJson("/api/v1/me/reservations/{$reservation->id}/payments", ['option' => 'balance'])->assertCreated();

        $this->actingAsUser($this->customer());
        $this->postJson("/api/v1/me/reservations/{$reservation->id}/payments", ['option' => 'balance'])->assertNotFound();
    }
}
