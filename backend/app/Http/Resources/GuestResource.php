<?php

namespace App\Http\Resources;

use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'nationality' => $this->nationality,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'address' => $this->address,
            'company' => $this->company,
            'is_vip' => $this->is_vip,
            'notes' => $this->notes,
            'has_account' => $this->user_id !== null,
            'reservations_count' => $this->whenCounted('reservations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
