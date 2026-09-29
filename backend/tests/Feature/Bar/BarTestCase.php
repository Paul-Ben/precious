<?php

namespace Tests\Feature\Bar;

use App\Models\BarCategory;
use App\Models\BarProduct;
use App\Models\BarTable;
use App\Models\Property;
use App\Models\User;
use Tests\TestCase;

abstract class BarTestCase extends TestCase
{
    protected BarTable $table;

    /** @var array<string, BarProduct> */
    protected array $products = [];

    protected User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::current();
        $drinks = BarCategory::create(['property_id' => $property->id, 'name' => 'Drinks']);

        foreach (['Mojito' => '6000.00', 'Water' => '800.00', 'Coke' => '1000.00'] as $name => $price) {
            $this->products[$name] = BarProduct::create(['property_id' => $property->id, 'category_id' => $drinks->id, 'name' => $name, 'price' => $price]);
        }

        $this->table = BarTable::create(['property_id' => $property->id, 'name' => 'Table 6', 'capacity' => 4]);
        $this->waiter = $this->staff('Waiter');
    }

    /** Opens a tab on Table 6 as the waiter. */
    protected function openTab(array $customer = ['customer_name' => 'Ada', 'customer_email' => 'ada@example.com']): string
    {
        return $this->actingAsUser($this->waiter)
            ->postJson('/api/v1/bar/tabs', ['table_id' => $this->table->id, ...$customer])
            ->assertCreated()
            ->json('data.id');
    }

    /** 2 × Mojito, 2 × Water, 2 × Coke = ₦15,600 before charges. */
    protected function placeStandardOrder(string $tabId): string
    {
        return $this->actingAsUser($this->waiter)
            ->postJson("/api/v1/bar/tabs/{$tabId}/orders", ['items' => [
                ['product_id' => $this->products['Mojito']->id, 'quantity' => 2, 'notes' => 'Less ice'],
                ['product_id' => $this->products['Water']->id, 'quantity' => 2],
                ['product_id' => $this->products['Coke']->id, 'quantity' => 2],
            ]])
            ->assertCreated()
            ->json('data.id');
    }

    /** Moves an order through the bar and delivers it. */
    protected function deliver(string $orderId): void
    {
        $bartender = $this->staff('Bartender');
        $this->actingAsUser($bartender)->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'READY'])->assertOk();
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/orders/{$orderId}/status", ['status' => 'DELIVERED'])->assertOk();
    }
}
