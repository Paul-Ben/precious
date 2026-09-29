<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: permission:users.update
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'two_factor_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
