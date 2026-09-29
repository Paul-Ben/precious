<?php

namespace App\Http\Resources\Bar;

use App\Models\BarOrder;
use App\Models\BarOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BarOrder */
class BarOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'waiter' => $this->whenLoaded('waiter', fn () => $this->waiter ? ['id' => $this->waiter->id, 'name' => $this->waiter->name] : null),
            'tab' => $this->whenLoaded('tab', fn () => [
                'id' => $this->tab->id,
                'number' => $this->tab->number,
                'table' => $this->tab->relationLoaded('table') ? $this->tab->table?->name : null,
                'customer_name' => $this->tab->customer_name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (BarOrderItem $i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'unit_price' => $i->unit_price,
                'quantity' => $i->quantity,
                'line_total' => $i->line_total,
                'notes' => $i->notes,
            ])->values()),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'preparing_at' => $this->preparing_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
