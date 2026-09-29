<?php

namespace App\Http\Resources;

use App\Models\Stay;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Stay
 */
class StayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'close_reason' => $this->close_reason,
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'number' => $this->room->number] : null),
            'reservation_room_id' => $this->reservation_room_id,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_in_by' => $this->whenLoaded('checkedInBy', fn () => $this->checkedInBy?->name),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'id_type' => $this->id_type,
            'id_number_masked' => $this->maskedIdNumber(),
            'notes' => $this->notes,
            'reservation' => $this->whenLoaded('reservation', fn () => [
                'id' => $this->reservation->id,
                'number' => $this->reservation->number,
                'check_in' => $this->reservation->check_in->toDateString(),
                'check_out' => $this->reservation->check_out->toDateString(),
                'balance' => Money::toDecimal($this->reservation->balanceMinor()),
            ]),
            'guest' => $this->whenLoaded('guest', fn () => $this->guest ? ['id' => $this->guest->id, 'full_name' => $this->guest->fullName(), 'phone' => $this->guest->phone] : null),
        ];
    }
}
