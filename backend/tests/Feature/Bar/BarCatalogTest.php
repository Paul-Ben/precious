<?php

namespace Tests\Feature\Bar;

use PHPUnit\Framework\Attributes\Test;

class BarCatalogTest extends BarTestCase
{
    #[Test]
    public function managers_maintain_the_menu_and_tables(): void
    {
        $this->actingAsUser($this->staff('Hotel Manager'));

        $categoryId = $this->postJson('/api/v1/bar/categories', ['name' => 'Cocktails'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/bar/categories', ['name' => 'Cocktails'])->assertStatus(422);

        $productId = $this->postJson('/api/v1/bar/products', ['category_id' => $categoryId, 'name' => 'Chapman', 'price' => '4000'])
            ->assertCreated()->assertJsonPath('data.price', '4000.00')->json('data.id');
        $this->patchJson("/api/v1/bar/products/{$productId}", ['price' => '4500.5'])->assertOk()->assertJsonPath('data.price', '4500.50');

        $this->postJson('/api/v1/bar/tables', ['name' => 'Terrace 1', 'capacity' => 6, 'area' => 'Terrace'])->assertCreated();

        $menu = $this->getJson('/api/v1/bar/menu')->assertOk()->json('data');
        $this->assertSame(['Cocktails', 'Drinks'], collect($menu)->pluck('name')->sort()->values()->all());

        $this->deleteJson("/api/v1/bar/categories/{$categoryId}")->assertStatus(422)->assertJsonPath('code', 'CATEGORY_IN_USE');
        $this->deleteJson("/api/v1/bar/products/{$productId}")->assertOk();
    }

    #[Test]
    public function waiters_see_the_menu_but_cannot_change_it(): void
    {
        $this->actingAsUser($this->waiter);

        $this->getJson('/api/v1/bar/menu')->assertOk()->assertJsonPath('data.0.products.0.name', 'Coke');
        $this->getJson('/api/v1/bar/tables')->assertOk()->assertJsonPath('data.0.name', 'Table 6');
        $this->postJson('/api/v1/bar/products', ['category_id' => 1, 'name' => 'X', 'price' => '1'])->assertForbidden();
        $this->postJson('/api/v1/bar/tables', ['name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function a_table_with_an_open_bill_cannot_be_freed(): void
    {
        $this->openTab();

        $this->actingAsUser($this->staff('Hotel Manager'))
            ->patchJson("/api/v1/bar/tables/{$this->table->id}/status", ['status' => 'AVAILABLE'])
            ->assertStatus(409)->assertJsonPath('code', 'TABLE_IN_USE');

        $this->getJson('/api/v1/bar/tables')->assertJsonPath('data.0.status', 'OCCUPIED')->assertJsonCount(1, 'data.0.open_tabs');
    }
}
