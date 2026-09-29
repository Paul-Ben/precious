<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\PaymentGatewaySetting;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlutterwaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->enableGateway('paystack');
        $this->enableGateway('flutterwave', default: false);

        Http::fake([
            'api.flutterwave.com/v3/payments' => fn (HttpRequest $r) => Http::response([
                'status' => 'success',
                'message' => 'Hosted Link',
                'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/'.$r['tx_ref']],
            ]),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => function (HttpRequest $r) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
                $payment = Payment::where('reference', $query['tx_ref'])->firstOrFail();

                return Http::response(['status' => 'success', 'message' => 'Transaction fetched successfully', 'data' => [
                    'id' => 288200108,
                    'tx_ref' => $payment->reference,
                    'status' => 'successful',
                    // Flutterwave returns JSON numbers in naira.
                    'amount' => (float) $payment->charged_amount,
                    'currency' => 'NGN',
                    'app_fee' => 987.45,
                    'payment_type' => 'card',
                    'created_at' => now()->toIso8601String(),
                ]]);
            },
        ]);
    }

    #[Test]
    public function guests_can_choose_flutterwave_and_its_fee_is_shown(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));

        $options = $this->getJson("/api/v1/public/reservations/{$reservation->number}/payment-options?token={$token}")
            ->assertJsonCount(2, 'data.gateways')
            ->assertJsonPath('data.gateways.0.gateway', 'paystack') // default first
            ->json('data.options.0.by_gateway');

        $this->assertSame(['gateway' => 'flutterwave', 'fee' => '987.25', 'total' => '49362.25'], $options[1]);

        $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", [
            'token' => $token, 'option' => 'deposit', 'gateway' => 'flutterwave',
        ])->assertCreated()->assertJsonPath('data.gateway', 'flutterwave')->assertJsonPath('data.charged_amount', '49362.25');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v3/payments')
            && $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-secret')
            && $r['amount'] === '49362.25'
            && $r['customer']['email'] === 'john.doe@example.com');
    }

    #[Test]
    public function a_flutterwave_webhook_with_the_secret_hash_confirms_the_payment(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType('Deluxe', '50000.00'));
        $reference = $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", [
            'token' => $token, 'option' => 'deposit', 'gateway' => 'flutterwave',
        ])->json('data.reference');

        $payload = ['event' => 'charge.completed', 'data' => ['id' => 288200108, 'tx_ref' => $reference, 'status' => 'successful']];

        $this->postJson('/api/v1/webhooks/payments/flutterwave', $payload, ['verif-hash' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/v1/webhooks/payments/flutterwave', $payload, ['verif-hash' => 'flw-hash-123'])
            ->assertOk()->assertJsonPath('data.outcome', 'SUCCESSFUL');

        $payment = Payment::where('reference', $reference)->firstOrFail();
        $this->assertSame('987.45', $payment->gateway_fee);
        $this->assertSame('CONFIRMED', $reservation->refresh()->status->value);
    }

    #[Test]
    public function a_disabled_gateway_cannot_be_chosen(): void
    {
        [$reservation, $token] = $this->bookOnline($this->roomType());
        PaymentGatewaySetting::where('gateway', 'flutterwave')->update(['is_enabled' => false]);

        $this->postJson("/api/v1/public/reservations/{$reservation->number}/payments", [
            'token' => $token, 'option' => 'deposit', 'gateway' => 'flutterwave',
        ])->assertStatus(422)->assertJsonPath('code', 'GATEWAY_DISABLED');
    }
}
