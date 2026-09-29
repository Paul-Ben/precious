<?php

namespace App\Models;

use App\Enums\StayStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest occupying a room. Charge-to-room (M4) targets OPEN stays only.
 */
class Stay extends Model
{
    use HasUuids;

    protected $fillable = [
        'property_id', 'reservation_id', 'reservation_room_id', 'room_id', 'guest_id', 'status',
        'close_reason', 'checked_in_at', 'checked_in_by', 'closed_at', 'closed_by', 'id_type',
        'id_number', 'notes',
    ];

    protected $hidden = ['id_number'];

    protected function casts(): array
    {
        return [
            'status' => StayStatus::class,
            'checked_in_at' => 'datetime',
            'closed_at' => 'datetime',
            'id_number' => 'encrypted',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reservationRoom(): BelongsTo
    {
        return $this->belongsTo(ReservationRoom::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', StayStatus::Open->value);
    }

    public function maskedIdNumber(): ?string
    {
        $n = $this->id_number;

        return $n ? str_repeat('•', max(0, strlen($n) - 4)).substr($n, -4) : null;
    }
}
