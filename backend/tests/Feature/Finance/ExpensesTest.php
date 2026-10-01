<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class ExpensesTest extends FinanceTestCase
{
    #[Test]
    public function p29_p30_small_expenses_count_straight_away(): void
    {
        $this->expense('20000')
            ->assertCreated()
            ->assertJsonPath('data.number', 'EXP-2026-00001')
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.amount', '20000.00')
            ->assertJsonPath('data.method', 'CASH')
            ->assertJsonPath('data.category.name', 'Utilities (power, diesel, water)')
            ->assertJsonPath('data.recorded_by.id', $this->accountant->id);

        $this->expense('50000')->assertCreated()->assertJsonPath('data.status', 'APPROVED'); // the limit itself

        $this->getJson('/api/v1/finance/expenses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.approved_total', '70000.00')
            ->assertJsonPath('meta.approval_limit', '50000.00');

        $this->assertTrue(AuditLog::where('action', 'expenses.created')->exists());
        $this->getJson('/api/v1/finance/expense-categories')->assertOk()->assertJsonCount(10, 'data');
    }

    #[Test]
    public function p30_larger_expenses_wait_for_someone_else_to_approve(): void
    {
        $id = $this->expense('80000', ['method' => 'BANK_TRANSFER', 'reference' => 'TRF-9'])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.id');

        // The accountant cannot approve at all; a manager can't approve their own.
        $this->postJson("/api/v1/finance/expenses/{$id}/approve")->assertForbidden();
        $own = $this->expense('90000', [], $this->manager)->json('data.id');
        $this->postJson("/api/v1/finance/expenses/{$own}/approve")->assertStatus(403)->assertJsonPath('code', 'SELF_APPROVAL');

        $this->postJson("/api/v1/finance/expenses/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.decided_by', $this->manager->name);
        $this->postJson("/api/v1/finance/expenses/{$id}/approve")->assertStatus(409);

        $other = $this->expense('120000')->json('data.id');
        $this->actingAsUser($this->manager)->postJson("/api/v1/finance/expenses/{$other}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/finance/expenses/{$other}/reject", ['reason' => 'No invoice'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.rejection_reason', 'No invoice');

        $this->getJson('/api/v1/finance/expenses?status=PENDING')->assertJsonCount(1, 'data')->assertJsonPath('meta.pending_count', 1);
    }

    #[Test]
    public function p31_pending_expenses_are_edited_approved_ones_only_voided(): void
    {
        $pending = $this->expense('80000')->json('data.id');

        $this->patchJson("/api/v1/finance/expenses/{$pending}", ['description' => 'Diesel (300 litres)'])
            ->assertOk()->assertJsonPath('data.description', 'Diesel (300 litres)')->assertJsonPath('data.status', 'PENDING');
        // Brought under the limit: it counts straight away.
        $this->patchJson("/api/v1/finance/expenses/{$pending}", ['amount' => '45000'])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->patchJson("/api/v1/finance/expenses/{$pending}", ['amount' => '46000'])->assertStatus(409)->assertJsonPath('code', 'EXPENSE_LOCKED');
        $this->postJson("/api/v1/finance/expenses/{$pending}/void", [])->assertStatus(422);
        $this->postJson("/api/v1/finance/expenses/{$pending}/void", ['reason' => 'Entered twice'])
            ->assertOk()->assertJsonPath('data.status', 'VOID')->assertJsonPath('data.void_reason', 'Entered twice');

        $this->getJson('/api/v1/finance/expenses')->assertJsonPath('meta.approved_total', '0.00');
        $this->assertTrue(AuditLog::where('action', 'expenses.voided')->exists());
    }

    #[Test]
    public function expenses_are_validated_and_receipts_are_private(): void
    {
        Storage::fake('local');

        $this->expense('5000', ['expense_date' => '2026-10-06'])->assertStatus(422)->assertJsonValidationErrors('expense_date');
        $this->expense('0')->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->expense('5000', ['method' => 'CRYPTO'])->assertStatus(422);

        $id = $this->actingAsUser($this->accountant)->post('/api/v1/finance/expenses', [
            'category_id' => $this->category('Bar stock')->id,
            'expense_date' => '2026-10-04',
            'description' => 'Crate of malt',
            'amount' => '12500.50',
            'method' => 'POS',
            'receipt' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_receipt', true)->json('data.id');

        $this->get("/api/v1/finance/expenses/{$id}/receipt")->assertOk();
        $this->actingAsUser($this->waiter)->get("/api/v1/finance/expenses/{$id}/receipt")->assertForbidden();
    }

    #[Test]
    public function only_finance_staff_see_expenses(): void
    {
        $this->actingAsUser($this->waiter)->getJson('/api/v1/finance/expenses')->assertForbidden();
        $this->actingAsUser($this->staff('Receptionist'))->postJson('/api/v1/finance/expenses', [])->assertForbidden();

        $this->actingAsUser($this->manager)->postJson('/api/v1/finance/expense-categories', ['name' => 'Security'])->assertCreated();
        $this->actingAsUser($this->accountant)->postJson('/api/v1/finance/expense-categories', ['name' => 'Gifts'])->assertForbidden();
        $this->actingAsUser($this->staff('Auditor'))->getJson('/api/v1/finance/expenses')->assertOk();
    }
}
