<?php

namespace Tests\Feature\Finance;

use App\Models\ExpenseCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Bar\BarTestCase;

abstract class FinanceTestCase extends BarTestCase
{
    protected User $accountant;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Monday 5 Oct 2026, 10:00 hotel time.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
        $this->accountant = $this->staff('Accountant');
        $this->manager = $this->staff('Hotel Manager');
    }

    protected function category(string $name = 'Utilities (power, diesel, water)'): ExpenseCategory
    {
        return ExpenseCategory::query()->where('name', $name)->firstOrFail();
    }

    /** Records an expense as $by (default: the accountant). */
    protected function expense(string $amount, array $extra = [], ?User $by = null): TestResponse
    {
        return $this->actingAsUser($by ?? $this->accountant)->postJson('/api/v1/finance/expenses', [
            'category_id' => $this->category()->id,
            'expense_date' => '2026-10-05',
            'description' => 'Diesel for generator',
            'payee' => 'Total filling station',
            'amount' => $amount,
            'method' => 'CASH',
            ...$extra,
        ]);
    }

    /** A delivered ₦18,447 bar bill paid in cash at the table; returns the tab id. */
    protected function cashBarSale(): string
    {
        $tabId = $this->openTab();
        $this->deliver($this->placeStandardOrder($tabId));
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/payments", ['method' => 'CASH', 'amount' => '18447'])->assertCreated();

        return $tabId;
    }
}
