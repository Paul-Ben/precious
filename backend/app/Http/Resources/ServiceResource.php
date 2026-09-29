<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'price' => $this->price,
            'charges_vat' => $this->charges_vat,
            'charges_service_charge' => $this->charges_service_charge,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
