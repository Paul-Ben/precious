<?php

namespace App\Http\Requests\Stays;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = 'regex:/^\d{1,11}(\.\d{1,2})?$/';
        $service = $this->filled('service_id');
        $adjustment = $this->input('category') === 'ADJUSTMENT';

        return [
            'service_id' => ['nullable', 'integer'],
            'category' => [$service ? 'prohibited' : 'required', Rule::in(['OTHER', 'ADJUSTMENT'])],
            'description' => [$service ? 'nullable' : 'required', 'string', 'max:255'],
            'quantity' => [$adjustment ? 'prohibited' : 'nullable', 'string', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'unit_price' => [$service || $adjustment ? 'prohibited' : 'required', 'string', $money],
            'amount' => [$adjustment ? 'required' : 'prohibited', 'string', $money, 'not_in:0,0.0,0.00'],
            'charges_vat' => ['sometimes', 'boolean'],
            'charges_service_charge' => ['sometimes', 'boolean'],
            'stay_id' => ['nullable', 'uuid'],
            'reason' => [$adjustment ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
