<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class RoomTypeImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120', 'dimensions:min_width=600,min_height=400,max_width=8000,max_height=8000'],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }
}
