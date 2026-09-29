<?php

namespace App\Http\Requests\Stays;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:120'],
            'category' => [$req, 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => [$req, 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            'charges_vat' => ['sometimes', 'boolean'],
            'charges_service_charge' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
