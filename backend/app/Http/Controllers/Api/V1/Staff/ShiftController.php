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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The rota (spec §31, P24) and manager attendance corrections (P25). */
class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'integer'],
            'include_cancelled' => ['nullable', 'boolean'],
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 62) {
            throw ValidationException::withMessages(['to' => 'Show up to two months at a time.']);
        }

        $shifts = Shift::query()
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when(! $request->boolean('include_cancelled'), fn ($q) => $q->where('status', ShiftStatus::Scheduled->value))
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($data['department_id'] ?? null, fn ($q, $id) => $q->where('department_id', $id))
            ->with(['user.staffProfile', 'department', 'template', 'attendance'])
            ->orderBy('starts_at')
            ->get();

        return ApiResponse::success(ShiftResource::collection($shifts));
    }

    public function store(Request $request): JsonResponse
    {
        $shift = $this->shifts->create($this->validated($request), $request->user());

        $message = $shift->starts_at->isFuture() ? 'Shift added. '.$shift->user->name.' has been emailed.' : 'Shift added.';

        return ApiResponse::created(new ShiftResource($shift->load(['user.staffProfile', 'attendance'])), $message);
    }

    public function update(Request $request, Shift $shift): JsonResponse
    {
        $shift = $this->shifts->update($shift, $this->validated($request, true), $request->user());

        return ApiResponse::success(new ShiftResource($shift->load(['user.staffProfile', 'attendance'])), 'Shift updated.');
    }

    public function cancel(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $shift = $this->shifts->cancel($shift, $data['reason'] ?? null, $request->user());

        return ApiResponse::success(new ShiftResource($shift->load(['user.staffProfile', 'attendance'])), 'Shift cancelled.');
    }

    public function copyWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_week' => ['required', 'date_format:Y-m-d'],
            'to_week' => ['required', 'date_format:Y-m-d', 'different:from_week'],
        ]);

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from_week']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to_week']);

        if (! $from->isMonday() || ! $to->isMonday()) {
            throw ValidationException::withMessages(['from_week' => 'Weeks start on a Monday.']);
        }

        // Compare calendar days: $to is midnight in the app time zone, not the hotel's.
        if ($to->toDateString() < CarbonImmutable::now(Property::current()->timezone)->startOfWeek()->toDateString()) {
            throw ValidationException::withMessages(['to_week' => 'Copy into this week or a later one.']);
        }

        $result = $this->shifts->copyWeek($from, $to, $request->user());

        return ApiResponse::success($result, "{$result['created']} shift(s) copied".($result['skipped'] ? ', '.count($result['skipped']).' skipped.' : '.'));
    }

    public function correctAttendance(Request $request, Shift $shift): JsonResponse
    {
        $data = $request->validate([
            'absent' => ['sometimes', 'boolean'],
            'clock_in_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'clock_out_at' => ['nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->shifts->correct($shift, $data, $request->user());

        return ApiResponse::success(new ShiftResource($shift->refresh()->load(['user.staffProfile', 'department', 'template', 'attendance.correctedBy'])), 'Attendance saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';
        // New shifts need a template or both times; edits may change either alone.
        $time = $partial ? ['sometimes', 'nullable', 'date_format:H:i'] : ['nullable', 'required_without:template_id', 'date_format:H:i'];

        return $request->validate([
            'user_id' => [$rule, 'uuid', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'date' => [$rule, 'date_format:Y-m-d'],
            'template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('shift_templates', 'id')],
            'start_time' => $time,
            'end_time' => $time,
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'location' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }
}
