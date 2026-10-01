<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    public const CLOSED = 'CLOSED';

    public const REOPENED = 'REOPENED';

    protected $fillable = [
        'property_id', 'date', 'status', 'summary', 'cash_expected', 'cash_counted', 'cash_difference', 'note',
        'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'summary' => 'array',
            'cash_expected' => 'decimal:2',
            'cash_counted' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by')->withTrashed();
    }
}
