<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use SoftDeletes;

    /** Suggested categories (spec §21); free text is allowed. */
    public const CATEGORIES = ['Laundry', 'Room Service', 'Restaurant', 'Spa', 'Transport', 'Extra Bed', 'Conference', 'Other'];

    protected $fillable = [
        'property_id', 'name', 'category', 'description', 'price', 'charges_vat',
        'charges_service_charge', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'charges_vat' => 'boolean',
            'charges_service_charge' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
