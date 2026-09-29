<?php

namespace Tests\Feature\Bar;

use App\Events\BarOrderChanged;
use App\Models\AuditLog;
use App\Notifications\BarBillNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class BarOrderFlowTest extends BarTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Event::fake([BarOrderChanged::class]);
    }

    #[Test]
    public function a_waiter_opens_a_bill_and_sends_an_order_to_the_bar(): void
    {
        $tabId = $this->openTab();
        $this->assertSame('OCCUPIED', $this->table->refresh()->status->value);

        $this->placeStandardOrder($tabId);

        // ₦15,600 + 10% service charge ₦1,560 + 7.5% VAT on ₦17,160 = ₦1,287.
        $this->getJson("/api/v1/bar/tabs/{$tabId}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '15600.00')
            ->assertJsonPath('data.service_charge', '1560.00')
            ->assertJsonPath('data.vat', '1287.00')
            ->assertJsonPath('data.total', '18447.00')
            ->assertJsonPath('data.balance', '18447.00')
            ->assertJsonPath('data.orders.0.status', 'PLACED')
            ->assertJsonPath('data.orders.0.items.0.name', 'Mojito')
            ->assertJsonPath('data.orders.0.items.0.notes', 'Less ice');

        Event::assertDispatched(BarOrderChanged::class);
        // P20: the customer gets the running bill with a pay link.
        Notification::assertSentOnDemand(BarBillNotification::class,
            fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'ada@example.com' && $n->final === false);
        $this->assertTrue(AuditLog::where('action', 'bar.order_placed')->exists());
    }

    #[Test]
    public function walk_in_customers_do_not_need_contact_details(): void
    {
        $tabId = $this->openTab([]);
        $this->placeStandardOrder($tabId);

        Notification::assertNothingSent();
    }

    #[Test]
    public function sold_out_products_cannot_be_ordered(): void
    {
        $tabId = $this->openTab();
        $this->actingAsUser($this->staff('Bartender'))
            ->patchJson("/api/v1/bar/products/{$this->products['Mojito']->id}/availability", ['is_available' => false])
            ->assertOk();

        $this->actingAsUser($this->waiter)
            ->postJson("/api/v1/bar/tabs/{$tabId}/orders", ['items' => [['product_id' => $this->products['Mojito']->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
    }

    #[Test]
    public function the_bartender_queue_moves_orders_to_ready_and_the_waiter_delivers(): void
    {
        $orderId = $this->placeStandardOrder($this->openTab());
        $bartender = $this->staff('Bartender');

        $this->actingAsUser($bartender)->getJson('/api/v1/bar/orders/queue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tab.table', 'Table 6');

        $this->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'ACCEPTED'])->assertOk()->assertJsonPath('data.status', 'ACCEPTED');
        $this->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'PLACED'])->assertStatus(422);
        $this->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'PREPARING'])->assertOk();
        $this->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'READY'])->assertOk();
        // Bartenders prepare, waiters deliver.
        $this->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'DELIVERED'])->assertForbidden();

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'DELIVERED'])
            ->assertOk()->assertJsonPath('data.status', 'DELIVERED');

        $this->actingAsUser($bartender)->getJson('/api/v1/bar/orders/queue')->assertJsonCount(0, 'data');
        Event::assertDispatchedTimes(BarOrderChanged::class, 5);
    }

    #[Test]
    public function waiters_cannot_skip_the_bar(): void
    {
        $orderId = $this->placeStandardOrder($this->openTab());

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'ACCEPTED'])->assertForbidden();
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'DELIVERED'])->assertStatus(409);
    }

    #[Test]
    public function p21_cancelling_orders(): void
    {
        $tabId = $this->openTab();
        $first = $this->placeStandardOrder($tabId);

        // The waiter can cancel their own order before the bar accepts it.
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->getJson("/api/v1/bar/tabs/{$tabId}")->assertJsonPath('data.total', '0.00');

        $second = $this->placeStandardOrder($tabId);
        $this->actingAsUser($this->staff('Bartender'))->postJson("/api/v1/bar/orders/{$second}/status", ['status' => 'ACCEPTED'])->assertOk();

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$second}/cancel", ['reason' => 'Changed mind'])
            ->assertForbidden();

        $this->actingAsUser($this->staff('Hotel Manager'));
        $this->postJson("/api/v1/bar/orders/{$second}/cancel")->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
        $this->postJson("/api/v1/bar/orders/{$second}/cancel", ['reason' => 'Wrong table'])->assertOk();
    }

    #[Test]
    public function p22_only_managers_give_discounts(): void
    {
        $tabId = $this->openTab();
        $this->placeStandardOrder($tabId);

        $this->actingAsUser($this->waiter)
            ->postJson("/api/v1/bar/tabs/{$tabId}/discount", ['amount' => '1600', 'reason' => 'Regular'])
            ->assertForbidden();

        // ₦15,600 − ₦1,600 = ₦14,000 + SC ₦1,400 + VAT ₦1,155.
        $this->actingAsUser($this->staff('Hotel Manager'))
            ->postJson("/api/v1/bar/tabs/{$tabId}/discount", ['amount' => '1600', 'reason' => 'Regular customer'])
            ->assertOk()
            ->assertJsonPath('data.discount', '1600.00')
            ->assertJsonPath('data.total', '16555.00');

        $this->postJson("/api/v1/bar/tabs/{$tabId}/discount", ['amount' => '99999', 'reason' => 'Too much'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_AMOUNT');
    }

    #[Test]
    public function only_bar_staff_can_take_orders(): void
    {
        $this->actingAsUser($this->staff('Bartender'))->postJson('/api/v1/bar/tabs', ['table_id' => $this->table->id])->assertForbidden();
        $this->actingAsUser($this->staff('Receptionist'))->getJson('/api/v1/bar/orders/queue')->assertForbidden();
    }
}
