<?php

namespace App\Http\Requests\Payments;

use Illuminate\Foundation\Http\FormRequest;

class RefundDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A reason is required to reject, optional to approve.
            'note' => [$this->routeIs('*.reject') ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
