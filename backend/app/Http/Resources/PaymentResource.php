<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    private bool $staffView = false;

    public function forStaff(bool $staff = true): static
    {
        $this->staffView = $staff;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $staff = $this->staffView;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'gateway' => $this->gateway,
            'gateway_mode' => $this->gateway_mode?->value,
            'channel' => $this->channel,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'customer_fee' => $this->customer_fee,
            'charged_amount' => $this->charged_amount,
            'refunded_amount' => $this->refunded_amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'receipt_number' => $this->whenLoaded('receipt', fn () => $this->receipt?->number),
            $this->mergeWhen($staff, fn () => [
                'gateway_fee' => $this->gateway_fee,
                'gateway_transaction_id' => $this->gateway_transaction_id,
                'external_reference' => $this->external_reference,
                'note' => $this->note,
                'payer_email' => $this->payer_email,
                'failure_reason' => $this->failure_reason,
                'needs_attention' => $this->needs_attention,
                'attention_reason' => $this->attention_reason,
                'verify_attempts' => $this->verify_attempts,
                'last_verified_at' => $this->last_verified_at?->toIso8601String(),
                'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy ? ['id' => $this->recordedBy->id, 'name' => $this->recordedBy->name] : null),
                'refundable' => $this->isSuccessful() ? Money::toDecimal(max(0, $this->refundableMinor())) : '0.00',
                'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn (Refund $r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'amount' => $r->amount,
                    'status' => $r->status->value,
                ])->values()),
                'payable' => $this->payable instanceof Reservation ? [
                    'type' => 'reservation',
                    'id' => $this->payable->id,
                    'number' => $this->payable->number,
                ] : null,
                'guest' => $this->whenLoaded('guest', fn () => $this->guest ? ['id' => $this->guest->id, 'full_name' => $this->guest->fullName()] : null),
            ]),
        ];
    }
}
