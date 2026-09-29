<?php

namespace App\Http\Resources\Bar;

use App\Models\BarTab;
use App\Models\BarTable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BarTable */
class BarTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'area' => $this->area,
            'status' => $this->status->value,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'open_tabs' => $this->whenLoaded('tabs', fn () => $this->tabs->map(fn (BarTab $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'customer_name' => $t->customer_name,
                'total' => $t->total,
                'opened_at' => $t->opened_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
