<?php

namespace App\Models;

use App\Enums\ChargeCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationCharge extends Model
{
    use HasUuids;

    protected $fillable = [
        'reservation_id', 'stay_id', 'service_id', 'category', 'description', 'quantity', 'unit_price',
        'subtotal', 'service_charge', 'vat', 'total', 'status', 'reason', 'created_by', 'voided_at',
        'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'category' => ChargeCategory::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'vat' => 'decimal:2',
            'total' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }
}
