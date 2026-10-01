<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Domain\Audit\AuditService;
use App\Domain\Staff\ShiftService;
use App\Enums\AttendanceStatus;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Staff\ShiftResource;
use App\Models\Shift;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Attendance records, hours worked per person and the CSV export (P28). No pay or payroll. */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly AuditService $audit,
    ) {}

    /** Shifts that have started in the range, with their attendance. `exceptions=1`: late, absent, open or flagged only. */
    public function index(Request $request): JsonResponse
    {
        $data = $this->range($request, ['user_id' => ['nullable', 'uuid'], 'exceptions' => ['nullable', 'boolean']]);

        $shifts = $this->started($data)
            ->when($request->boolean('exceptions'), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereDoesntHave('attendance')
                ->orWhereHas('attendance', fn (Builder $a) => $a
                    ->where('status', '!=', AttendanceStatus::ClockedOut->value)
                    ->orWhere('is_late', true)
                    ->orWhere('needs_review', true))))
            ->with(['user.staffProfile', 'department', 'template', 'attendance.correctedBy'])
            ->orderByDesc('starts_at')
            ->get();

        return ApiResponse::success(ShiftResource::collection($shifts));
    }

    /** Per person: shifts, hours scheduled and worked, late and absent counts. */
    public function summary(Request $request): JsonResponse
    {
        $data = $this->range($request);

        $rows = $this->started($data)
            ->with(['user.staffProfile.department', 'attendance'])
            ->get()
            ->groupBy('user_id')
            ->map(function ($shifts) {
                $user = $shifts->first()->user;
                $records = $shifts->pluck('attendance')->filter();

                return [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'employee_number' => $user->staffProfile?->employee_number,
                    'department' => $user->staffProfile?->department?->name,
                    'shifts' => $shifts->count(),
                    'scheduled_minutes' => $shifts->sum(fn (Shift $s) => $s->minutes()),
                    'worked_minutes' => $records->sum(fn ($r) => $r->workedMinutes()),
                    'late' => $records->where('is_late', true)->count(),
                    'late_minutes' => $records->sum('late_minutes'),
                    'absent' => $records->where('status', AttendanceStatus::Absent)->count(),
                    'needs_review' => $records->where('needs_review', true)->count(),
                ];
            })
            ->sortBy('name')
            ->values();

        return ApiResponse::success([
            'from' => $data['from'],
            'to' => $data['to'],
            'people' => $rows,
            'totals' => [
                'shifts' => $rows->sum('shifts'),
                'worked_minutes' => $rows->sum('worked_minutes'),
                'late' => $rows->sum('late'),
                'absent' => $rows->sum('absent'),
                'needs_review' => $rows->sum('needs_review'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->range($request);
        $this->audit->record('attendance.exported', null, null, null, ['from' => $data['from'], 'to' => $data['to']]);
        $local = fn ($at) => $at ? $this->shifts->local($at)->format('Y-m-d H:i') : null;

        return response()->streamDownload(function () use ($data, $local) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Employee no.', 'Name', 'Department', 'Location', 'Shift', 'Starts', 'Ends', 'Clock in', 'Clock out', 'Status', 'Late (min)', 'Worked (h)', 'Note'], ',', '"', '');

            foreach ($this->started($data)->with(['user.staffProfile', 'department', 'template', 'attendance'])->orderBy('starts_at')->orderBy('id')->lazy(500) as $shift) {
                $a = $shift->attendance;
                $status = match (true) {
                    $a === null => 'Not recorded',
                    $a->status === AttendanceStatus::Absent => 'Absent',
                    $a->status === AttendanceStatus::ClockedIn => 'Clocked in',
                    default => $a->is_late ? 'Late' : 'Present',
                };
                $note = $a?->auto_closed ? 'Clock-out not recorded (closed at shift end)' : ($a?->correction_reason ? 'Corrected: '.$a->correction_reason : '');

                $row = [
                    $shift->date->toDateString(), $shift->user?->staffProfile?->employee_number, $shift->user?->name,
                    $shift->department?->name, $shift->location, $shift->template?->name ?? 'Custom',
                    $local($shift->starts_at), $local($shift->ends_at), $local($a?->clock_in_at), $local($a?->clock_out_at),
                    $status, $a?->late_minutes ?: '', $a ? number_format($a->workedMinutes() / 60, 2, '.', '') : '', $note,
                ];

                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $row), ',', '"', '');
            }

            fclose($out);
        }, "attendance_{$data['from']}_{$data['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function range(Request $request, array $extra = []): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ...$extra,
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a range of up to one year.']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function started(array $data): Builder
    {
        return Shift::query()
            ->where('status', ShiftStatus::Scheduled->value)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->where('starts_at', '<=', now())
            ->when($data['user_id'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id));
    }
}
