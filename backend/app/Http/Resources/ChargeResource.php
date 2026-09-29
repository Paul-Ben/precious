<?php

namespace App\Http\Resources;

use App\Models\ReservationCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReservationCharge
 */
class ChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'subtotal' => $this->subtotal,
            'service_charge' => $this->service_charge,
            'vat' => $this->vat,
            'total' => $this->total,
            'status' => $this->status,
            'stay_id' => $this->stay_id,
            'service_id' => $this->service_id,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'voided_by' => $this->whenLoaded('voidedBy', fn () => $this->voidedBy?->name),
            'void_reason' => $this->void_reason,
        ];
    }
}
