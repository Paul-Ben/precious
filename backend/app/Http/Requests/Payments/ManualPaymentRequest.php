<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::manual()))],
            'amount' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
            // A transfer must be traceable; a POS slip number is strongly recommended.
            'external_reference' => [Rule::requiredIf($this->input('method') === PaymentMethod::BankTransfer->value), 'nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'send_receipt' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['external_reference.required' => 'Enter the bank transfer reference or sender name.'];
    }
}
