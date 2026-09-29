<?php

namespace App\Http\Requests\Hotel;

use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';
        $room = $this->route('room');

        return [
            'room_type_id' => [$req, 'integer', Rule::exists('room_types', 'id')->whereNull('deleted_at')],
            'number' => [
                $req, 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('rooms', 'number')
                    ->where('property_id', Property::current()->id)
                    ->ignore($room?->id),
            ],
            'floor' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
