<?php

namespace App\Models;

use App\Enums\BarTabStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A customer's running bar bill for one visit (spec §22-27).
 */
class BarTab extends Model
{
    use HasUuids;

    protected $fillable = [
        'number', 'property_id', 'table_id', 'waiter_id', 'customer_name', 'customer_phone', 'customer_email',
        'status', 'settlement', 'subtotal', 'discount', 'discount_reason', 'discount_by', 'service_charge', 'vat',
        'total', 'amount_paid', 'stay_id', 'reservation_charge_id', 'pay_token', 'opened_at', 'closed_at',
        'closed_by', 'close_note',
    ];

    protected $hidden = ['pay_token'];

    protected function casts(): array
    {
        return [
            'status' => BarTabStatus::class,
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'vat' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'pay_token' => 'encrypted',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(BarTable::class, 'table_id');
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(BarOrder::class, 'tab_id')->orderBy('placed_at');
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->latest();
    }

    public function balanceMinor(): int
    {
        // Settled bills owe nothing (a later refund does not reopen them).
        if ($this->settlement === 'CHARGED_TO_ROOM' || $this->status !== BarTabStatus::Open) {
            return 0;
        }

        return Money::toMinor($this->total) - Money::toMinor($this->amount_paid);
    }

    public function tokenMatches(string $token): bool
    {
        return is_string($this->pay_token) && strlen($token) === 40 && hash_equals($this->pay_token, $token);
    }

    public function payUrl(): string
    {
        return config('security.frontend_url').'/bar/pay/'.$this->number.'?token='.$this->pay_token;
    }

    public function isOpen(): bool
    {
        return $this->status === BarTabStatus::Open;
    }
}
