<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'email', 'phone', 'nationality',
        'date_of_birth', 'address', 'company', 'is_vip', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_vip' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GuestDocument::class)->latest();
    }

    /** Reservations where this guest is the booking guest. */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Every reservation this guest appears on (booker or companion). */
    public function stays(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guests')->withPivot('is_primary')->withTimestamps();
    }
}
