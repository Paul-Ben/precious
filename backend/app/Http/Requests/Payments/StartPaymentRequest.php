<?php

namespace App\Http\Requests\Payments;

use App\Domain\Payments\GatewayRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Start an online payment (guest link or signed-in customer). */
class StartPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Guest lookup token - required on the public route only.
            'token' => [$this->routeIs('public.*') ? 'required' : 'prohibited', 'string', 'size:40'],
            'option' => ['required', Rule::in(['deposit', 'balance'])],
            'gateway' => ['nullable', Rule::in(array_keys(GatewayRegistry::definitions()))],
        ];
    }
}
