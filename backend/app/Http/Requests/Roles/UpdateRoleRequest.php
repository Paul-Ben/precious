<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: permission:roles.update
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:60', Rule::unique('roles', 'name')->ignore($this->route('role'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
