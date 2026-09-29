<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'rooms' => ['required', 'array', 'min:1', 'max:10'],
            'rooms.*.room_type_id' => ['required', 'integer'],
            'rooms.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }
}
