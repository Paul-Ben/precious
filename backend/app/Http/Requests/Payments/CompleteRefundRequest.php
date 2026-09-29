<?php

namespace App\Http\Requests\Payments;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(RefundMethod::class)],
            'external_reference' => [Rule::requiredIf($this->input('method') !== RefundMethod::Cash->value), 'nullable', 'string', 'max:100'],
        ];
    }
}
