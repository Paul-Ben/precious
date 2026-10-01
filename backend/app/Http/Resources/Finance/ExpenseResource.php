<?php

namespace App\Http\Resources\Finance;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name]),
            'expense_date' => $this->expense_date->toDateString(),
            'description' => $this->description,
            'payee' => $this->payee,
            'amount' => $this->amount,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'reference' => $this->reference,
            'status' => $this->status->value,
            'has_receipt' => (bool) $this->receipt_path,
            'receipt_name' => $this->receipt_name,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? ['id' => $this->recordedBy->id, 'name' => $this->recordedBy->name] : null),
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'voided_by' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
