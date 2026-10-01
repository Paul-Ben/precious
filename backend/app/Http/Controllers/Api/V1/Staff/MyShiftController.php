<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Domain\Staff\ShiftService;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\ShiftResource;
use App\Models\Property;
use App\Models\Shift;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** P25/P28: every staff member's own shifts, clock in/out and who they work with. */
class MyShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::now(Property::current()->timezone)->startOfDay();
        $from = $data['from'] ?? $today->subDays(7)->toDateString();
        $to = $data['to'] ?? $today->addDays(27)->toDateString();

        $mine = Shift::query()
            ->where('user_id', $request->user()->id)
            ->whereBetween('date', [$from, $to])
            ->with(['department', 'template', 'attendance'])
            ->orderBy('starts_at')
            ->get();

        $scheduled = $mine->where('status', ShiftStatus::Scheduled);
        $others = $scheduled->isEmpty() ? collect() : Shift::query()
            ->where('status', ShiftStatus::Scheduled->value)
            ->where('user_id', '!=', $request->user()->id)
            ->where('starts_at', '<', $scheduled->max('ends_at'))
            ->where('ends_at', '>', $scheduled->min('starts_at'))
            ->with(['user', 'department'])
            ->get();

        return ApiResponse::success($mine->map(function (Shift $shift) use ($others, $request) {
            $colleagues = $shift->isScheduled() ? $others
                ->filter(fn (Shift $o) => $o->starts_at->lt($shift->ends_at) && $o->ends_at->gt($shift->starts_at))
                ->sortBy(fn (Shift $o) => $o->user->name)
                ->map(fn (Shift $o) => [
                    'name' => $o->user->name,
                    'department' => $o->department?->name,
                    'location' => $o->location,
                    'start_time' => $this->shifts->local($o->starts_at)->format('H:i'),
                    'end_time' => $this->shifts->local($o->ends_at)->format('H:i'),
                ])->values()->all() : [];

            return (new ShiftResource($shift))->forOwner($colleagues)->resolve($request);
        })->values());
    }

    public function clockIn(Request $request, Shift $shift): JsonResponse
    {
        $this->shifts->clockIn($shift, $request->user());

        return ApiResponse::success($this->present($shift, $request), 'Clocked in at '.$this->shifts->local(now())->format('H:i').'.');
    }

    public function clockOut(Request $request, Shift $shift): JsonResponse
    {
        $this->shifts->clockOut($shift, $request->user());

        return ApiResponse::success($this->present($shift, $request), 'Clocked out at '.$this->shifts->local(now())->format('H:i').'.');
    }

    /** @return array<string, mixed> */
    private function present(Shift $shift, Request $request): array
    {
        return (new ShiftResource($shift->refresh()->load(['department', 'template', 'attendance'])))->forOwner()->resolve($request);
    }
}
