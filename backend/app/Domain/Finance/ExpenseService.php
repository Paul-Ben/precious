<?php

namespace App\Domain\Finance;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Enums\ExpenseMethod;
use App\Enums\ExpenseStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\DocumentSequence;
use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses (P29–P31).
 *
 * - Up to the approval limit an expense counts straight away; above it, it waits
 *   for someone with `finance.expenses.approve` who did not record it.
 * - Pending expenses can be edited; approved ones can only be voided (with a reason).
 * - Cash expenses on a closed day are frozen (P33).
 */
class ExpenseService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly HotelSettings $settings,
        private readonly DayLock $days,
    ) {}

    /**
     * @param  array{category_id: int, expense_date: string, description: string, payee?: ?string, amount: string, method: string, reference?: ?string}  $data
     */
    public function create(array $data, User $actor, ?UploadedFile $receipt = null): Expense
    {
        $needsApproval = $this->needsApproval($data['amount']);

        $expense = DB::transaction(function () use ($data, $actor, $needsApproval) {
            $this->assertDayOpen($data['expense_date'], $data['method']);

            $expense = Expense::query()->create([
                ...$this->only($data),
                'property_id' => Property::current()->id,
                'number' => DocumentSequence::next('EXP', (int) $this->settings->today()->year),
                'status' => $needsApproval ? ExpenseStatus::Pending : ExpenseStatus::Approved,
                'decided_at' => $needsApproval ? null : now(),
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record('expenses.created', $expense, null, $this->values($expense), ['auto_approved' => ! $needsApproval]);

            return $expense;
        });

        if ($receipt) {
            $this->storeReceipt($expense, $receipt);
        }

        return $expense->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($expense->status !== ExpenseStatus::Pending) {
                throw new BusinessRuleException('Only expenses waiting for approval can be edited. Void this one and record it again.', 'EXPENSE_LOCKED', 409);
            }

            $this->assertDayOpen($expense->expense_date, $expense->method->value);
            $this->assertDayOpen($data['expense_date'] ?? $expense->expense_date, $data['method'] ?? $expense->method->value);

            $before = $this->values($expense);
            $expense->fill($this->only($data));

            // Brought down to the limit or below: it no longer needs approval.
            if (! $this->needsApproval($expense->amount)) {
                $expense->forceFill(['status' => ExpenseStatus::Approved, 'decided_at' => now()]);
            }

            $expense->save();
            [$old, $new] = $this->audit->diff($before, $this->values($expense));

            if ($new !== []) {
                $this->audit->record('expenses.updated', $expense, $old, $new);
            }

            return $expense->refresh();
        });
    }

    public function approve(Expense $expense, User $actor): Expense
    {
        return $this->decide($expense, $actor, ExpenseStatus::Approved);
    }

    public function reject(Expense $expense, string $reason, User $actor): Expense
    {
        return $this->decide($expense, $actor, ExpenseStatus::Rejected, $reason);
    }

    public function void(Expense $expense, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor) {
            /** @var Expense $expense */
            $expense = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! in_array($expense->status, [ExpenseStatus::Pending, ExpenseStatus::Approved], true)) {
                throw new BusinessRuleException('Only pending or approved expenses can be voided.', 'INVALID_STATUS', 409);
            }

            $this->assertDayOpen($expense->expense_date, $expense->method->value);
            $old = $expense->status->value;

            $expense->forceFill([
                'status' => ExpenseStatus::Void,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record('expenses.voided', $expense, ['status' => $old], ['status' => 'VOID'], ['reason' => $reason]);

            return $expense;
        });
    }

    public function storeReceipt(Expense $expense, UploadedFile $file): Expense
    {
        if (in_array($expense->status, [ExpenseStatus::Void, ExpenseStatus::Rejected], true)) {
            throw new BusinessRuleException('This expense is closed.', 'INVALID_STATUS', 409);
        }

        $disk = config('hotel.documents_disk');
        $path = $file->storeAs('expense-receipts/'.$expense->expense_date->format('Y/m'), $expense->number.'-'.Str::random(6).'.'.$file->extension(), $disk);
        $old = [$expense->receipt_disk, $expense->receipt_path];

        $expense->forceFill(['receipt_disk' => $disk, 'receipt_path' => $path, 'receipt_name' => Str::limit($file->getClientOriginalName(), 180, '')])->save();
        $this->audit->record('expenses.receipt_attached', $expense);

        if ($old[0] && $old[1]) {
            Storage::disk($old[0])->delete($old[1]);
        }

        return $expense;
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        abort_unless($expense->receipt_path && Storage::disk($expense->receipt_disk)->exists($expense->receipt_path), 404);

        return Storage::disk($expense->receipt_disk)->response(
            $expense->receipt_path,
            $expense->number.'.'.pathinfo($expense->receipt_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function approvalLimit(): string
    {
        return (string) $this->settings->get('expense_approval_above');
    }

    private function needsApproval(string $amount): bool
    {
        return Money::toMinor($amount) > Money::toMinor($this->approvalLimit());
    }

    private function decide(Expense $expense, User $actor, ExpenseStatus $to, ?string $reason = null): Expense
    {
        return DB::transaction(function () use ($expense, $actor, $to, $reason) {
            /** @var Expense $locked */
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ExpenseStatus::Pending) {
                throw new BusinessRuleException('This expense has already been decided.', 'INVALID_STATUS', 409);
            }

            if ($locked->recorded_by === $actor->id) {
                throw new BusinessRuleException('Someone else must approve an expense you recorded.', 'SELF_APPROVAL', 403);
            }

            // A rejected cash expense leaves the closed day's expected cash (pending ones count there).
            if ($to === ExpenseStatus::Rejected) {
                $this->assertDayOpen($locked->expense_date, $locked->method->value);
            }

            $locked->forceFill([
                'status' => $to,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->audit->record($to === ExpenseStatus::Approved ? 'expenses.approved' : 'expenses.rejected', $locked,
                ['status' => 'PENDING'], ['status' => $to->value], $reason ? ['reason' => $reason] : []);

            return $locked;
        });
    }

    private function assertDayOpen(\DateTimeInterface|string $date, string $method): void
    {
        if ($method === ExpenseMethod::Cash->value) {
            $this->days->assertOpen($date, 'This cash expense');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(['category_id', 'expense_date', 'description', 'payee', 'amount', 'method', 'reference']));
    }

    /** @return array<string, mixed> */
    private function values(Expense $expense): array
    {
        return [
            'category_id' => $expense->category_id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'description' => $expense->description,
            'payee' => $expense->payee,
            'amount' => $expense->amount,
            'method' => $expense->method?->value,
            'reference' => $expense->reference,
            'status' => $expense->status?->value,
        ];
    }
}
