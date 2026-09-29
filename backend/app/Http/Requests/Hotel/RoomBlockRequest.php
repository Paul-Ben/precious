<?php

namespace App\Http\Requests\Hotel;

use App\Enums\RoomBlockReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'reason' => ['required', Rule::enum(RoomBlockReason::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
