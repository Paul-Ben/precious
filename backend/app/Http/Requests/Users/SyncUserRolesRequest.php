<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware: permission:roles.assign
    }

    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')],
        ];
    }
}
