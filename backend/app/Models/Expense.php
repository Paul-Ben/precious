<?php

namespace App\Models;

use App\Enums\ExpenseMethod;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasUuids;

    protected $fillable = [
        'property_id', 'number', 'category_id', 'expense_date', 'description', 'payee', 'amount', 'method',
        'reference', 'status', 'receipt_disk', 'receipt_path', 'receipt_name', 'recorded_by', 'decided_by',
        'decided_at', 'rejection_reason', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'method' => ExpenseMethod::class,
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by')->withTrashed();
    }

    /** Counts towards expenses and the net position. */
    public function counts(): bool
    {
        return $this->status === ExpenseStatus::Approved;
    }
}
