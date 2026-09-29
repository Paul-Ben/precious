<?php

namespace App\Models;

use App\Enums\BarOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BarOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'number', 'tab_id', 'waiter_id', 'status', 'notes', 'subtotal', 'placed_at', 'accepted_at', 'accepted_by',
        'preparing_at', 'ready_at', 'delivered_at', 'delivered_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BarOrderStatus::class,
            'subtotal' => 'decimal:2',
            'placed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tab(): BelongsTo
    {
        return $this->belongsTo(BarTab::class, 'tab_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BarOrderItem::class, 'order_id')->orderBy('id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }
}
