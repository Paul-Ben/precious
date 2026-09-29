<?php

namespace App\Models;

use App\Enums\BarTableStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BarTable extends Model
{
    protected $fillable = ['property_id', 'name', 'capacity', 'area', 'status', 'is_active', 'sort_order'];

    /** Mirrors the column defaults so a freshly created model is complete. */
    protected $attributes = ['status' => 'AVAILABLE', 'capacity' => 4, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'status' => BarTableStatus::class,
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function tabs(): HasMany
    {
        return $this->hasMany(BarTab::class, 'table_id');
    }
}
