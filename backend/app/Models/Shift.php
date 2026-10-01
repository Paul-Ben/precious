<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shift extends Model
{
    use HasUuids;

    protected $fillable = [
        'property_id', 'user_id', 'template_id', 'department_id', 'location', 'date', 'starts_at', 'ends_at',
        'status', 'notes', 'cancel_reason', 'cancelled_at', 'created_by',
    ];

    protected $attributes = ['status' => 'SCHEDULED'];

    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class, 'template_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class);
    }

    public function isScheduled(): bool
    {
        return $this->status === ShiftStatus::Scheduled;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->lte(now());
    }

    /** Length in minutes. */
    public function minutes(): int
    {
        return (int) round($this->starts_at->diffInMinutes($this->ends_at));
    }
}
