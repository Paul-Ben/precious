<?php

namespace Tests\Feature\Bar;

use App\Models\BarTab;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\ReservationCharge;
use App\Notifications\BarBillNotification;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class BarSettlementTest extends BarTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function a_bill_is_closed_only_when_everything_is_delivered_and_paid(): void
    {
        $tabId = $this->openTab();
        $orderId = $this->placeStandardOrder($tabId);

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/close")
            ->assertStatus(409)->assertJsonPath('code', 'ORDERS_PENDING');

        $this->deliver($orderId);

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/close")
            ->assertStatus(409)->assertJsonPath('code', 'BALANCE_DUE')->assertJsonPath('balance', '18447.00');

        $this->postJson("/api/v1/bar/tabs/{$tabId}/payments", ['method' => 'POS', 'amount' => '18447', 'external_reference' => 'POS-12'])
            ->assertCreated()
            ->assertJsonPath('data.payable.type', 'bar_tab')
            ->assertJsonPath('data.recorded_by.id', $this->waiter->id);

        $this->postJson("/api/v1/bar/tabs/{$tabId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.settlement', 'PAID')
            ->assertJsonPath('data.balance', '0.00');

        $this->assertSame('AVAILABLE', $this->table->refresh()->status->value);
        $receipt = Receipt::firstOrFail();
        $this->assertSame('bar_tab', $receipt->snapshot['for']['type']);
        $this->assertSame('Table 6', $receipt->snapshot['for']['table']);
        Notification::assertSentOnDemand(BarBillNotification::class, fn ($n) => $n->final === true);

        // The cash-up includes bar money.
        $this->actingAsUser($this->staff('Accountant'))->getJson('/api/v1/payments/summary')->assertJsonPath('data.received', '18447.00');
    }

    #[Test]
    public function an_empty_bill_is_cancelled_on_close(): void
    {
        $tabId = $this->openTab();

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/close")
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('AVAILABLE', $this->table->refresh()->status->value);
    }

    #[Test]
    public function p9_a_bill_is_charged_to_a_checked_in_guest_by_room_and_surname(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
        $reservation = $this->checkIn($this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201'])));

        $tabId = $this->openTab();
        $this->deliver($this->placeStandardOrder($tabId));

        $this->actingAsUser($this->waiter);
        $this->postJson("/api/v1/bar/tabs/{$tabId}/charge-to-room", ['room_number' => '201', 'surname' => 'Smith'])
            ->assertStatus(422)->assertJsonPath('code', 'ROOM_GUEST_MISMATCH');
        $this->postJson("/api/v1/bar/tabs/{$tabId}/charge-to-room", ['room_number' => '202', 'surname' => 'Doe'])
            ->assertStatus(422)->assertJsonPath('code', 'ROOM_GUEST_MISMATCH');

        $this->postJson("/api/v1/bar/tabs/{$tabId}/charge-to-room", ['room_number' => '201', 'surname' => 'doe'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.settlement', 'CHARGED_TO_ROOM')
            ->assertJsonPath('data.charged_to.room', '201')
            ->assertJsonPath('data.balance', '0.00');

        $charge = ReservationCharge::firstOrFail();
        $this->assertSame('BAR', $charge->category->value);
        $this->assertSame('18447.00', $charge->total);
        $this->assertSame($tabId, $charge->bar_tab_id);
        $this->assertSame('18447.00', $reservation->refresh()->charges_total);
        $this->assertSame(18_447_00, $reservation->balanceMinor());
    }

    #[Test]
    public function charging_to_a_room_needs_the_permission_and_an_in_house_guest(): void
    {
        $tabId = $this->openTab();
        $this->deliver($this->placeStandardOrder($tabId));

        $this->actingAsUser($this->staff('Service Agent'))
            ->postJson("/api/v1/bar/tabs/{$tabId}/charge-to-room", ['room_number' => '201', 'surname' => 'Doe'])
            ->assertForbidden();

        // Booked but not checked in: not chargeable.
        $this->confirmedBooking($this->roomType('Deluxe', '50000.00', ['201']));
        $this->actingAsUser($this->waiter)
            ->postJson("/api/v1/bar/tabs/{$tabId}/charge-to-room", ['room_number' => '201', 'surname' => 'Doe'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_customer_pays_from_the_emailed_link(): void
    {
        $this->enableGateway('paystack');
        Http::fake([
            'api.paystack.co/transaction/initialize' => fn (HttpRequest $r) => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/'.$r['reference'], 'access_code' => 'x'],
            ]),
            'api.paystack.co/transaction/verify/*' => function (HttpRequest $r) {
                $payment = Payment::where('reference', basename(parse_url($r->url(), PHP_URL_PATH)))->firstOrFail();

                return Http::response(['status' => true, 'data' => [
                    'id' => 1, 'status' => 'success', 'amount' => Money::toMinor($payment->charged_amount),
                    'currency' => 'NGN', 'fees' => 0, 'channel' => 'card', 'paid_at' => now()->toIso8601String(),
                ]]);
            },
        ]);

        $tabId = $this->openTab();
        $this->placeStandardOrder($tabId);
        $tab = BarTab::findOrFail($tabId);
        $this->withoutBearer();

        $this->getJson("/api/v1/public/bar-tabs/{$tab->number}?token=".str_repeat('x', 40))->assertNotFound();
        $this->getJson("/api/v1/public/bar-tabs/{$tab->number}?token={$tab->pay_token}")
            ->assertOk()
            ->assertJsonPath('data.total', '18447.00')
            ->assertJsonMissingPath('data.customer_email');

        $this->getJson("/api/v1/public/bar-tabs/{$tab->number}/payment-options?token={$tab->pay_token}")
            ->assertJsonPath('data.options.0.amount', '18447.00')
            ->assertJsonPath('data.options.0.by_gateway.0.gateway', 'paystack');

        $reference = $this->postJson("/api/v1/public/bar-tabs/{$tab->number}/payments", ['token' => $tab->pay_token])
            ->assertCreated()->json('data.reference');

        $this->postJson('/api/v1/public/payments/verify', ['reference' => $reference])
            ->assertOk()
            ->assertJsonPath('data.status', 'SUCCESSFUL')
            ->assertJsonPath('data.bar_tab.number', $tab->number)
            ->assertJsonPath('data.bar_tab.balance', '0.00');

        $this->assertSame('18447.00', $tab->refresh()->amount_paid);
        $this->assertStringContainsString('/bar/pay/'.$tab->number.'?token=', $tab->payUrl());
    }
}
