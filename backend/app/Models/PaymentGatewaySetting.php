<?php

namespace App\Models;

use App\Enums\GatewayMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGatewaySetting extends Model
{
    protected $fillable = [
        'gateway',
        'display_name',
        'is_enabled',
        'is_default',
        'mode',
        'credentials',
        'options',
        'last_tested_at',
        'last_test_mode',
        'last_test_succeeded',
        'last_test_message',
        'updated_by',
    ];

    /** Credentials must never be serialised by accident. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'mode' => GatewayMode::class,
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Credential value for the given mode, e.g. credential('secret_key', live).
     */
    public function credential(string $field, ?GatewayMode $mode = null): ?string
    {
        $mode ??= $this->mode;
        $value = ($this->credentials ?? [])[$mode->value.'_'.$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
