<?php

namespace App\Http\Resources;

use App\Models\Refund;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;
        $payment = $this->relationLoaded('payment') ? $this->payment : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'requires_second_approval' => $this->requires_second_approval,
            'method' => $this->method?->value,
            'external_reference' => $this->external_reference,
            'decision_note' => $this->decision_note,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $person($this->requestedBy)),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $person($this->approvedBy)),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $person($this->rejectedBy)),
            'completed_by' => $this->whenLoaded('completedBy', fn () => $person($this->completedBy)),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'payment' => $payment ? [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'method' => $payment->method->value,
                'method_label' => $payment->method->label(),
                'gateway' => $payment->gateway,
                'amount' => $payment->amount,
                'payable_number' => $payment->payable?->number,
                'payable_id' => $payment->payable_id,
            ] : null,
        ];
    }
}
