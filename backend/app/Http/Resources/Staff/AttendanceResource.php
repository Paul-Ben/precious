<?php

namespace App\Http\Resources\Staff;

use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceRecord
 */
class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'clock_in_at' => $this->clock_in_at?->toIso8601String(),
            'clock_out_at' => $this->clock_out_at?->toIso8601String(),
            'is_late' => $this->is_late,
            'late_minutes' => $this->late_minutes,
            'worked_minutes' => $this->workedMinutes(),
            'auto_closed' => $this->auto_closed,
            'needs_review' => $this->needs_review,
            'correction_reason' => $this->correction_reason,
            'corrected_by' => $this->whenLoaded('correctedBy', fn () => $this->correctedBy?->name),
            'corrected_at' => $this->corrected_at?->toIso8601String(),
        ];
    }
}
