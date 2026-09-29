<?php

namespace App\Http\Requests\Stays;

use App\Enums\GuestDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rooms' => ['sometimes', 'array', 'max:20'],
            'rooms.*.reservation_room_id' => ['required', 'integer'],
            'rooms.*.room_id' => ['required', 'integer'],
            // P15: ID seen at the desk when none is on the guest profile.
            'id_type' => ['nullable', 'required_with:id_number', Rule::enum(GuestDocumentType::class)],
            'id_number' => ['nullable', 'required_with:id_type', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
