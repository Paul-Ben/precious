<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Validation\Rule;

class StaffReservationRequest extends QuoteRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'source' => ['required', Rule::in(['FRONT_DESK', 'PHONE', 'WALK_IN'])],
            'guest_id' => ['nullable', 'uuid', Rule::exists('guests', 'id')->whereNull('deleted_at')],
            'guest' => ['required_without:guest_id', 'array'],
            'guest.first_name' => ['required_without:guest_id', 'string', 'max:80'],
            'guest.last_name' => ['required_without:guest_id', 'string', 'max:80'],
            'guest.email' => ['nullable', 'email:rfc', 'max:190'],
            'guest.phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'additional_guests' => ['sometimes', 'array', 'max:10'],
            'additional_guests.*.first_name' => ['required', 'string', 'max:80'],
            'additional_guests.*.last_name' => ['required', 'string', 'max:80'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'hold_minutes' => ['nullable', 'integer', 'min:5', 'max:4320'],
        ];
    }
}
