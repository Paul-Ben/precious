<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'shift_id', 'user_id', 'status', 'clock_in_at', 'clock_out_at', 'is_late', 'late_minutes',
        'auto_closed', 'needs_review', 'corrected_by', 'correction_reason', 'corrected_at',
    ];

    protected $attributes = ['is_late' => false, 'late_minutes' => 0, 'auto_closed' => false, 'needs_review' => false];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'corrected_at' => 'datetime',
            'is_late' => 'boolean',
            'late_minutes' => 'integer',
            'auto_closed' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /** Minutes actually worked (0 when absent or still clocked in). */
    public function workedMinutes(): int
    {
        if (! $this->clock_in_at || ! $this->clock_out_at) {
            return 0;
        }

        return (int) round($this->clock_in_at->diffInMinutes($this->clock_out_at));
    }
}
