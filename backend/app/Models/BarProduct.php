<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BarProduct extends Model
{
    use SoftDeletes;

    protected $fillable = ['property_id', 'category_id', 'name', 'description', 'price', 'is_available', 'is_active', 'sort_order'];

    protected $attributes = ['is_available' => true, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BarCategory::class, 'category_id');
    }
}
