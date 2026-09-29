<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Reservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'number', 'property_id', 'guest_id', 'booked_by', 'source', 'status', 'payment_status',
        'check_in', 'check_out', 'nights', 'adults', 'children', 'currency',
        'subtotal', 'service_charge_total', 'tax_total', 'total', 'charges_total', 'deposit_percent',
        'deposit_amount', 'amount_paid', 'pricing_snapshot', 'free_cancellation_hours',
        'expires_at', 'confirmed_at', 'checked_in_at', 'checked_out_at', 'checked_out_by', 'balance_at_checkout', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'refund_eligible', 'special_requests', 'internal_notes', 'lookup_token_hash',
    ];

    protected $hidden = ['lookup_token_hash'];

    protected function casts(): array
    {
        return [
            'source' => ReservationSource::class,
            'status' => ReservationStatus::class,
            'payment_status' => PaymentStatus::class,
            'check_in' => 'date',
            'check_out' => 'date',
            'nights' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal' => 'decimal:2',
            'service_charge_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'charges_total' => 'decimal:2',
            'balance_at_checkout' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'deposit_percent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'free_cancellation_hours' => 'integer',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_eligible' => 'boolean',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class)->orderBy('id');
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')->withPivot('is_primary')->withTimestamps();
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest();
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class)->orderBy('checked_in_at');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(ReservationCharge::class)->orderBy('created_at');
    }

    public function folioStatement(): HasOne
    {
        return $this->hasOne(FolioStatement::class);
    }

    /** Booked accommodation plus extras added during the stay, in kobo. */
    public function grandTotalMinor(): int
    {
        return Money::toMinor($this->total) + Money::toMinor($this->charges_total ?? '0');
    }

    public function balanceMinor(): int
    {
        return $this->grandTotalMinor() - Money::toMinor($this->amount_paid);
    }

    /** Reservations belonging to a customer account. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('booked_by', $user->id)
            ->orWhereHas('guest', fn (Builder $g) => $g->where('user_id', $user->id)));
    }
}
