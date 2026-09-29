<?php

namespace App\Models;

use App\Enums\GatewayMode;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\TransactionStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'reference', 'property_id', 'payable_type', 'payable_id', 'guest_id', 'method', 'gateway',
        'gateway_mode', 'purpose', 'status', 'currency', 'amount', 'customer_fee', 'charged_amount',
        'gateway_fee', 'refunded_amount', 'gateway_transaction_id', 'channel', 'authorization_url',
        'payer_email', 'external_reference', 'note', 'failure_reason', 'needs_attention',
        'attention_reason', 'verify_attempts', 'last_verified_at', 'paid_at', 'recorded_by',
    ];

    protected $hidden = ['authorization_url'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'purpose' => PaymentPurpose::class,
            'status' => TransactionStatus::class,
            'gateway_mode' => GatewayMode::class,
            'amount' => 'decimal:2',
            'customer_fee' => 'decimal:2',
            'charged_amount' => 'decimal:2',
            'gateway_fee' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'needs_attention' => 'boolean',
            'verify_attempts' => 'integer',
            'last_verified_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Successful;
    }

    /** Amount still available for new refund requests, in kobo. */
    public function refundableMinor(): int
    {
        $refunds = $this->relationLoaded('refunds') ? $this->refunds : $this->refunds()->get();

        $committed = $refunds
            ->filter(fn (Refund $r) => $r->status->isOpen())
            ->sum(fn (Refund $r) => Money::toMinor($r->amount));

        return Money::toMinor($this->amount) - $committed;
    }
}
