<?php

namespace App\Http\Requests\Stays;

use Illuminate\Foundation\Http\FormRequest;

class MoveStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
