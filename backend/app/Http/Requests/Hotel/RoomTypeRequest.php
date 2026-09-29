<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'base_rate' => [$req, 'decimal:0,2', 'min:0', 'max:99999999'],
            'max_adults' => [$req, 'integer', 'min:1', 'max:20'],
            'max_children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'max_occupancy' => [$req, 'integer', 'min:1', 'max:20', 'gte:max_adults'],
            'bed_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'size_sqm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'amenity_ids' => ['sometimes', 'array'],
            'amenity_ids.*' => ['integer', Rule::exists('amenities', 'id')],
        ];
    }
}
