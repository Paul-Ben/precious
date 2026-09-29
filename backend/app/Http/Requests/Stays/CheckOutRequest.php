<?php

namespace App\Http\Requests\Stays;

use Illuminate\Foundation\Http\FormRequest;

class CheckOutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'apply_late_fee' => ['sometimes', 'boolean'],
            // Waiving a due late fee must be explained (audited).
            'waive_reason' => ['nullable', 'string', 'min:3', 'max:255'],
            'override_balance' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'required_if_accepted:override_balance', 'string', 'min:5', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->has('apply_late_fee') && ! $this->boolean('apply_late_fee') && blank($this->input('waive_reason'))) {
                $validator->errors()->add('waive_reason', 'Give a reason for waiving the late check-out fee.');
            }
        }];
    }
}
