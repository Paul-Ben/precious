<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Room
 */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'floor' => $this->floor,
            'status' => $this->status->value,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'maintenance_notes' => $this->maintenance_notes,
            'room_type' => $this->whenLoaded('roomType', fn () => [
                'id' => $this->roomType->id,
                'name' => $this->roomType->name,
                'base_rate' => $this->roomType->base_rate,
            ]),
            'blocks' => RoomBlockResource::collection($this->whenLoaded('blocks')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
