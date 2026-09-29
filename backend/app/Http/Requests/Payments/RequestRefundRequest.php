<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
