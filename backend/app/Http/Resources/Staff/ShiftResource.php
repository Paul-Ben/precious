<?php

namespace App\Http\Resources\Staff;

use App\Domain\Staff\ShiftService;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    /** Adds can_clock_in / can_clock_out and colleagues (My shifts). */
    private bool $forOwner = false;

    /** @var list<array<string, mixed>> */
    private array $colleagues = [];

    /**
     * @param  list<array<string, mixed>>  $colleagues
     */
    public function forOwner(array $colleagues = []): static
    {
        $this->forOwner = true;
        $this->colleagues = $colleagues;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $service = app(ShiftService::class);
        $start = $service->local($this->starts_at);
        $end = $service->local($this->ends_at);

        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'overnight' => $end->toDateString() !== $start->toDateString(),
            'minutes' => $this->minutes(),
            'status' => $this->status->value,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'employee_number' => $this->user->relationLoaded('staffProfile') ? $this->user->staffProfile?->employee_number : null,
            ]),
            'template' => $this->whenLoaded('template', fn () => $this->template ? ['id' => $this->template->id, 'name' => $this->template->name] : null),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'location' => $this->location,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'attendance' => $this->whenLoaded('attendance', fn () => $this->attendance ? new AttendanceResource($this->attendance) : null),
            'can_clock_in' => $this->when($this->forOwner, fn () => $service->canClockIn($this->resource)),
            'can_clock_out' => $this->when($this->forOwner, fn () => $service->canClockOut($this->resource)),
            'colleagues' => $this->when($this->forOwner, fn () => $this->colleagues),
        ];
    }
}
