<?php

namespace App\Http\Requests\Stays;

use Illuminate\Foundation\Http\FormRequest;

class ExtendStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['check_out' => ['required', 'date_format:Y-m-d']];
    }
}
