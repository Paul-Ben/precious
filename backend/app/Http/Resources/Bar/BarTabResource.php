<?php

namespace App\Http\Resources\Bar;

use App\Http\Resources\PaymentResource;
use App\Models\BarTab;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BarTab */
class BarTabResource extends JsonResource
{
    private bool $staffView = false;

    public function forStaff(bool $staff = true): static
    {
        $this->staffView = $staff;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'settlement' => $this->settlement,
            'table' => $this->whenLoaded('table', fn () => $this->table ? ['id' => $this->table->id, 'name' => $this->table->name] : null),
            'waiter' => $this->whenLoaded('waiter', fn () => $this->waiter ? ['id' => $this->waiter->id, 'name' => $this->waiter->name] : null),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->when($this->staffView, $this->customer_phone),
            'customer_email' => $this->when($this->staffView, $this->customer_email),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount,
            'discount_reason' => $this->when($this->staffView, $this->discount_reason),
            'service_charge' => $this->service_charge,
            'vat' => $this->vat,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => Money::toDecimal(max(0, $this->balanceMinor())),
            'charged_to' => $this->when($this->settlement === 'CHARGED_TO_ROOM' && $this->relationLoaded('stay'), fn () => [
                'room' => $this->stay?->room?->number,
                'reservation_id' => $this->stay?->reservation_id,
            ]),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'orders' => $this->whenLoaded('orders', fn () => BarOrderResource::collection($this->orders)->resolve($request)),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                ->filter(fn (Payment $p) => $this->staffView || $p->isSuccessful())
                ->map(fn (Payment $p) => (new PaymentResource($p))->forStaff($this->staffView)->resolve($request))
                ->values()),
        ];
    }
}
