<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorChallenge extends Model
{
    use HasUuids, Prunable;

    protected $fillable = [
        'user_id',
        'code_hash',
        'purpose',
        'channel',
        'attempts',
        'expires_at',
        'last_sent_at',
        'consumed_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * Used/expired challenges older than a day are deleted by `model:prune`.
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('security.two_factor.max_attempts', 5);
    }
}
