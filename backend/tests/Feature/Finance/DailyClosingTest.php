<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\DailyClosing;
use PHPUnit\Framework\Attributes\Test;

class DailyClosingTest extends FinanceTestCase
{
    #[Test]
    public function p33_the_day_shows_expected_cash_by_method(): void
    {
        $this->cashBarSale();                                       // +18,447 cash
        $this->expense('5000');                                     // −5,000 cash, approved
        $this->expense('60000');                                    // −60,000 cash, waiting for approval: the cash has still left the drawer
        $this->expense('7000', ['method' => 'BANK_TRANSFER']);      // not cash

        $this->actingAsUser($this->accountant)->getJson('/api/v1/finance/day?date=2026-10-05')
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.received', '18447.00')
            ->assertJsonPath('data.bar', '18447.00')
            ->assertJsonPath('data.expenses.approved', '12000.00')
            ->assertJsonPath('data.expenses.cash_paid', '65000.00')
            ->assertJsonPath('data.expenses.pending_count', 1)
            ->assertJsonPath('data.cash.expected', '-46553.00');
    }

    #[Test]
    public function p33_closing_records_the_count_and_freezes_the_day(): void
    {
        $this->cashBarSale();
        $cashExpense = $this->expense('5000')->json('data.id');     // expected cash 13,447

        $this->postJson('/api/v1/finance/day/close', ['date' => '2026-10-05', 'cash_counted' => '13000'])
            ->assertStatus(422)->assertJsonPath('code', 'NOTE_REQUIRED');

        $this->postJson('/api/v1/finance/day/close', ['date' => '2026-10-05', 'cash_counted' => '13000', 'note' => '₦447 short, change given'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.closing.cash_expected', '13447.00')
            ->assertJsonPath('data.closing.cash_difference', '-447.00')
            ->assertJsonPath('data.closing.closed_by', $this->accountant->name);

        $this->postJson('/api/v1/finance/day/close', ['date' => '2026-10-05', 'cash_counted' => '13447'])
            ->assertStatus(409)->assertJsonPath('code', 'DAY_ALREADY_CLOSED');
        $this->assertTrue(AuditLog::where('action', 'finance.day_closed')->exists());

        // Frozen: cash expenses and desk payments for that day.
        $this->expense('1000')->assertStatus(409)->assertJsonPath('code', 'DAY_CLOSED');
        $this->postJson("/api/v1/finance/expenses/{$cashExpense}/void", ['reason' => 'Wrong'])->assertStatus(409)->assertJsonPath('code', 'DAY_CLOSED');
        $this->expense('1000', ['method' => 'BANK_TRANSFER'])->assertCreated();   // not cash: allowed

        $tabId = $this->openTab(['customer_name' => 'Bola']);
        $this->placeStandardOrder($tabId);
        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/payments", ['method' => 'CASH', 'amount' => '1000'])
            ->assertStatus(409)->assertJsonPath('code', 'DAY_CLOSED');

        // Only an administrator reopens, with a reason.
        $this->actingAsUser($this->accountant)->postJson('/api/v1/finance/day/reopen', ['date' => '2026-10-05', 'reason' => 'Late receipt'])->assertForbidden();
        $this->actingAsUser($this->staff('Administrator'))->postJson('/api/v1/finance/day/reopen', ['date' => '2026-10-05'])->assertStatus(422);
        $this->postJson('/api/v1/finance/day/reopen', ['date' => '2026-10-05', 'reason' => 'Late receipt'])
            ->assertOk()->assertJsonPath('data.status', 'REOPENED')->assertJsonPath('data.closing.reopen_reason', 'Late receipt');

        $this->actingAsUser($this->waiter)->postJson("/api/v1/bar/tabs/{$tabId}/payments", ['method' => 'CASH', 'amount' => '1000'])->assertCreated();

        // …and it can be closed again with the new figures.
        $this->actingAsUser($this->manager)->postJson('/api/v1/finance/day/close', ['date' => '2026-10-05', 'cash_counted' => '14447'])
            ->assertOk()->assertJsonPath('data.closing.cash_difference', '0.00');
        $this->assertSame(1, DailyClosing::count());
    }

    #[Test]
    public function only_started_days_close_and_only_finance_staff_close_them(): void
    {
        $this->actingAsUser($this->accountant)->postJson('/api/v1/finance/day/close', ['date' => '2026-10-06', 'cash_counted' => '0'])
            ->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_STARTED');
        $this->postJson('/api/v1/finance/day/close', ['date' => '2026-10-04', 'cash_counted' => '0'])->assertOk();
        $this->getJson('/api/v1/finance/closings?month=2026-10')->assertOk()->assertJsonCount(1, 'data.closings');

        $this->actingAsUser($this->staff('Receptionist'))->postJson('/api/v1/finance/day/close', ['date' => '2026-10-05', 'cash_counted' => '0'])->assertForbidden();
        $this->actingAsUser($this->waiter)->getJson('/api/v1/finance/day')->assertForbidden();
    }
}
