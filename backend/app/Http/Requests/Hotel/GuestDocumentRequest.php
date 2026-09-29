<?php

namespace App\Http\Requests\Hotel;

use App\Enums\GuestDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuestDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(GuestDocumentType::class)],
            'number' => ['nullable', 'string', 'max:50'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            // MIME is checked from the file contents, not the name.
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:8192'],
        ];
    }
}
