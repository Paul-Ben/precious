<?php

namespace App\Domain\Staff;

use App\Domain\Audit\AuditService;
use App\Domain\Property\HotelSettings;
use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\ShiftStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceRecord;
use App\Models\Property;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Notifications\ShiftNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Shifts and attendance (spec §31, P24–P27).
 *
 * Times: a shift belongs to the hotel calendar day it starts on; start/end
 * are "HH:MM" in the hotel time zone and an end at or before the start means
 * the next day. Timestamps are stored in the app time zone like every other
 * timestamp in the system.
 */
class ShiftService
{
    /** PostgreSQL SQLSTATE for exclusion-constraint violations (shifts_no_overlap). */
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private readonly AuditService $audit,
        private readonly HotelSettings $settings,
        private readonly StaffService $staff,
    ) {}

    /**
     * @param  array{user_id: string, date: string, template_id?: ?int, start_time?: ?string, end_time?: ?string, department_id?: ?int, location?: ?string, notes?: ?string}  $data
     */
    public function create(array $data, User $actor, bool $notify = true): Shift
    {
        $user = User::query()->findOrFail($data['user_id']);
        $this->assertSchedulable($user);
        [$templateId, $start, $end] = $this->resolveTimes($data);

        $shift = $this->guardOverlap($user, fn () => DB::transaction(function () use ($data, $user, $actor, $templateId, $start, $end) {
            $this->assertNoOverlap($user, $start, $end);

            $shift = Shift::query()->create([
                'property_id' => Property::current()->id,
                'user_id' => $user->id,
                'template_id' => $templateId,
                'department_id' => $data['department_id'] ?? $this->staff->ensureProfile($user)->department_id,
                'location' => $data['location'] ?? null,
                'date' => $data['date'],
                'starts_at' => $start,
                'ends_at' => $end,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('shifts.created', $shift, null, $this->auditValues($shift));

            return $shift;
        }));

        $shift->load(['user', 'department', 'template']);

        if ($notify && $shift->starts_at->isFuture()) {
            $user->notify(new ShiftNotification($shift, ShiftNotification::ASSIGNED));
        }

        return $shift;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Shift $shift, array $data, User $actor): Shift
    {
        $this->assertScheduled($shift);
        // Only values that actually differ count (edit forms resend unchanged fields).
        $timing = $this->changedTiming($shift, $data);

        if ($timing !== [] && $shift->hasStarted()) {
            throw new BusinessRuleException('This shift has already started. Only its notes and location can change; correct attendance instead.', 'SHIFT_STARTED', 409);
        }

        $previousDescription = ShiftNotification::describe($shift->loadMissing('department'));
        $oldUser = $shift->user;
        $before = $this->auditValues($shift);
        $guardUser = isset($timing['user_id']) ? User::query()->findOrFail($timing['user_id']) : $oldUser;

        $shift = $this->guardOverlap($guardUser, fn () => DB::transaction(function () use ($shift, $data, $timing) {
            if ($timing !== []) {
                $user = User::query()->findOrFail($timing['user_id'] ?? $shift->user_id);
                $this->assertSchedulable($user);
                $date = $timing['date'] ?? $shift->date->toDateString();

                if (! empty($timing['template_id'])) {
                    // A different standard shift: its times.
                    [$templateId, $start, $end] = $this->resolveTimes(['date' => $date, 'template_id' => $timing['template_id']]);
                } else {
                    // Untouched times keep their current values (also when only the date or person changes,
                    // so a template edited since keeps "shifts already on the rota keep their times").
                    [, $start, $end] = $this->resolveTimes([
                        'date' => $date,
                        'start_time' => $timing['start_time'] ?? $this->local($shift->starts_at)->format('H:i'),
                        'end_time' => $timing['end_time'] ?? $this->local($shift->ends_at)->format('H:i'),
                    ]);
                    $templateId = array_key_exists('template_id', $timing) || isset($timing['start_time']) || isset($timing['end_time'])
                        ? null
                        : $shift->template_id;
                }

                $this->assertNoOverlap($user, $start, $end, $shift->id);

                $shift->fill([
                    'user_id' => $user->id,
                    'date' => $date,
                    'template_id' => $templateId,
                    'starts_at' => $start,
                    'ends_at' => $end,
                ]);
            }

            $shift->fill(array_intersect_key($data, array_flip(['department_id', 'location', 'notes'])))->save();

            return $shift;
        }));

        $shift->refresh()->load(['user', 'department', 'template']);
        $after = $this->auditValues($shift);
        [$old, $new] = $this->audit->diff($before, $after);

        if ($new !== []) {
            $this->audit->record('shifts.updated', $shift, $old, $new);
        }

        // P27: tell the people affected, for shifts that have not started yet.
        if ($shift->starts_at->isFuture()) {
            if ($oldUser && $oldUser->id !== $shift->user_id) {
                $oldUser->notify(new ShiftNotification($shift, ShiftNotification::CANCELLED, $previousDescription));
                $shift->user->notify(new ShiftNotification($shift, ShiftNotification::ASSIGNED));
            } elseif (array_intersect(array_keys($new), ['date', 'starts_at', 'ends_at', 'department_id', 'location']) !== []) {
                $shift->user->notify(new ShiftNotification($shift, ShiftNotification::CHANGED, $previousDescription));
            }
        }

        return $shift;
    }

    public function cancel(Shift $shift, ?string $reason, User $actor): Shift
    {
        $this->assertScheduled($shift);

        if ($shift->hasStarted()) {
            throw new BusinessRuleException('This shift has already started and cannot be cancelled. Correct the attendance instead.', 'SHIFT_STARTED', 409);
        }

        $shift->forceFill([
            'status' => ShiftStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        $this->audit->record('shifts.cancelled', $shift, ['status' => 'SCHEDULED'], ['status' => 'CANCELLED'], ['reason' => $reason]);

        $shift->load(['user', 'department', 'template']);
        $shift->user->notify(new ShiftNotification($shift, ShiftNotification::CANCELLED));

        return $shift;
    }

    /**
     * P24: copies a week's scheduled shifts to another week. Shifts that would
     * clash, or belong to someone who can no longer be scheduled, are skipped.
     *
     * @return array{created: int, skipped: list<array{name: string, date: string, reason: string}>}
     */
    public function copyWeek(CarbonImmutable $fromMonday, CarbonImmutable $toMonday, User $actor): array
    {
        $offset = (int) $fromMonday->diffInDays($toMonday, false);
        $source = Shift::query()
            ->where('status', ShiftStatus::Scheduled->value)
            ->whereBetween('date', [$fromMonday->toDateString(), $fromMonday->addDays(6)->toDateString()])
            ->with('user')
            ->orderBy('starts_at')
            ->get();

        $created = 0;
        $skipped = [];

        foreach ($source as $shift) {
            $date = $shift->date->toImmutable()->addDays($offset)->toDateString();
            $times = [
                'date' => $date,
                'template_id' => $shift->template_id,
                'start_time' => $this->local($shift->starts_at)->format('H:i'),
                'end_time' => $this->local($shift->ends_at)->format('H:i'),
            ];

            try {
                // Copying into the current week must not create shifts that are already under
                // way or over (the attendance job would mark them absent straight away).
                if ($this->resolveTimes($times)[1]->lte(now())) {
                    throw new BusinessRuleException('This shift would already have started.', 'SHIFT_STARTED', 409);
                }

                $this->create([
                    ...$times,
                    'user_id' => $shift->user_id,
                    'department_id' => $shift->department_id,
                    'location' => $shift->location,
                    'notes' => $shift->notes,
                ], $actor);
                $created++;
            } catch (BusinessRuleException $e) {
                $skipped[] = ['name' => $shift->user?->name ?? '—', 'date' => $date, 'reason' => $e->getMessage()];
            }
        }

        $this->audit->record('shifts.week_copied', null, null, null, [
            'from' => $fromMonday->toDateString(), 'to' => $toMonday->toDateString(), 'created' => $created, 'skipped' => count($skipped),
        ]);

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------- attendance

    /** P25: the person on the shift clocks in from 30 minutes before it starts until it ends. */
    public function clockIn(Shift $shift, User $user): AttendanceRecord
    {
        $this->assertOwnScheduled($shift, $user);
        $now = CarbonImmutable::now();
        $opens = $shift->starts_at->toImmutable()->subMinutes($this->int('shift_clock_in_early_minutes'));

        if ($now->lt($opens)) {
            throw new BusinessRuleException('You can clock in from '.$this->local($opens)->format('H:i').'.', 'CLOCK_IN_TOO_EARLY', 409);
        }

        if ($now->gte($shift->ends_at)) {
            throw new BusinessRuleException('This shift has ended. Ask your manager to record your attendance.', 'SHIFT_ENDED', 409);
        }

        return DB::transaction(function () use ($shift, $user, $now) {
            // Serialises a double tap (the unique shift_id would otherwise surface as a 500).
            Shift::query()->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift->attendance()->exists()) {
                throw new BusinessRuleException('You have already clocked in for this shift.', 'ALREADY_CLOCKED_IN', 409);
            }

            $record = AttendanceRecord::query()->create([
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'status' => AttendanceStatus::ClockedIn,
                'clock_in_at' => $now,
                ...$this->lateness($shift, $now),
            ]);

            $this->audit->record('attendance.clocked_in', $shift, null, ['clock_in_at' => $now->toIso8601String(), 'late_minutes' => $record->late_minutes]);

            return $record;
        });
    }

    public function clockOut(Shift $shift, User $user): AttendanceRecord
    {
        $this->assertOwnScheduled($shift, $user);

        return DB::transaction(function () use ($shift) {
            $record = AttendanceRecord::query()->where('shift_id', $shift->id)->lockForUpdate()->first();

            if (! $record || $record->status !== AttendanceStatus::ClockedIn) {
                throw new BusinessRuleException('You are not clocked in for this shift.', 'NOT_CLOCKED_IN', 409);
            }

            $record->forceFill(['status' => AttendanceStatus::ClockedOut, 'clock_out_at' => now()])->save();
            $this->audit->record('attendance.clocked_out', $shift, null, ['clock_out_at' => now()->toIso8601String()]);

            return $record;
        });
    }

    /**
     * P25: a manager records or corrects attendance, with a reason.
     *
     * @param  array{absent?: bool, clock_in_at?: ?string, clock_out_at?: ?string, reason: string}  $data  times "Y-m-d H:i" in hotel time
     */
    public function correct(Shift $shift, array $data, User $actor): AttendanceRecord
    {
        $this->assertScheduled($shift);

        if (! $shift->hasStarted()) {
            throw new BusinessRuleException('Attendance can be recorded once the shift has started.', 'SHIFT_NOT_STARTED', 409);
        }

        $record = $shift->attendance ?? new AttendanceRecord(['shift_id' => $shift->id, 'user_id' => $shift->user_id]);
        $before = $record->exists ? $this->attendanceValues($record) : [];

        if (! empty($data['absent'])) {
            $record->fill([
                'status' => AttendanceStatus::Absent, 'clock_in_at' => null, 'clock_out_at' => null,
                'is_late' => false, 'late_minutes' => 0,
            ]);
        } else {
            $in = $this->parseLocal($data['clock_in_at'] ?? null) ?? $record->clock_in_at?->toImmutable();
            $out = array_key_exists('clock_out_at', $data) ? $this->parseLocal($data['clock_out_at']) : $record->clock_out_at?->toImmutable();

            if (! $in) {
                throw new BusinessRuleException('Enter the clock-in time, or mark the person absent.', 'CLOCK_IN_REQUIRED', 422);
            }

            if ($out && $out->lt($in)) {
                throw new BusinessRuleException('Clock-out must be after clock-in.', 'INVALID_TIMES', 422);
            }

            if ($in->isFuture() || $out?->isFuture()) {
                throw new BusinessRuleException('Times cannot be in the future.', 'INVALID_TIMES', 422);
            }

            $record->fill([
                'status' => $out ? AttendanceStatus::ClockedOut : AttendanceStatus::ClockedIn,
                'clock_in_at' => $in,
                'clock_out_at' => $out,
                ...$this->lateness($shift, $in),
            ]);
        }

        $record->fill([
            'auto_closed' => false,
            'needs_review' => false,
            'corrected_by' => $actor->id,
            'correction_reason' => $data['reason'],
            'corrected_at' => now(),
        ])->save();

        [$old, $new] = $this->audit->diff($before, $this->attendanceValues($record));
        $this->audit->record('attendance.corrected', $shift, $old, $new, ['reason' => $data['reason'], 'staff' => $shift->user?->name]);

        return $record->refresh();
    }

    /**
     * P26 (scheduled every few minutes): no clock-in by the end of a shift =
     * ABSENT; still clocked in some hours after the end = clocked out at the
     * shift end and flagged for the manager.
     *
     * @return array{absent: int, closed: int}
     */
    public function closeFinishedShifts(): array
    {
        $now = now();
        $absent = 0;
        $closed = 0;

        Shift::query()
            ->where('status', ShiftStatus::Scheduled->value)
            ->where('ends_at', '<=', $now)
            ->whereDoesntHave('attendance')
            ->lazyById(500)
            ->each(function (Shift $shift) use (&$absent) {
                AttendanceRecord::query()->create([
                    'shift_id' => $shift->id,
                    'user_id' => $shift->user_id,
                    'status' => AttendanceStatus::Absent,
                ]);
                $absent++;
            });

        AttendanceRecord::query()
            ->where('status', AttendanceStatus::ClockedIn->value)
            ->whereHas('shift', fn ($q) => $q->where('ends_at', '<=', $now->copy()->subHours($this->int('shift_auto_close_after_hours'))))
            ->with('shift')
            ->lazyById(500)
            ->each(function (AttendanceRecord $record) use (&$closed) {
                $record->forceFill([
                    'status' => AttendanceStatus::ClockedOut,
                    'clock_out_at' => $record->shift->ends_at->max($record->clock_in_at),
                    'auto_closed' => true,
                    'needs_review' => true,
                ])->save();
                $closed++;
            });

        return ['absent' => $absent, 'closed' => $closed];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $data  date + (template_id | start_time & end_time)
     * @return array{0: ?int, 1: CarbonImmutable, 2: CarbonImmutable} template id, start, end (app time zone)
     */
    public function resolveTimes(array $data): array
    {
        $templateId = null;

        if (! empty($data['template_id'])) {
            $template = ShiftTemplate::query()->findOrFail($data['template_id']);
            $templateId = $template->id;
            $startHm = $template->startHm();
            $endHm = $template->endHm();
        } else {
            $startHm = $data['start_time'] ?? null;
            $endHm = $data['end_time'] ?? null;

            if (! $startHm || ! $endHm) {
                throw new BusinessRuleException('Choose a shift or enter start and end times.', 'TIMES_REQUIRED', 422);
            }
        }

        if ($startHm === $endHm) {
            throw new BusinessRuleException('A shift must end after it starts.', 'INVALID_TIMES', 422);
        }

        $tz = Property::current()->timezone;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$startHm, $tz);
        $end = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$endHm, $tz);

        if ($end->lte($start)) {
            $end = $end->addDay(); // overnight
        }

        if ($start->diffInHours($end) > 16) {
            throw new BusinessRuleException('A shift cannot be longer than 16 hours.', 'SHIFT_TOO_LONG', 422);
        }

        $appTz = config('app.timezone');

        return [$templateId, $start->setTimezone($appTz), $end->setTimezone($appTz)];
    }

    /** A timestamp in hotel time. */
    public function local(\DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(Property::current()->timezone);
    }

    /** Clock-in/out windows for the person on the shift (load `attendance` first). */
    public function canClockIn(Shift $shift): bool
    {
        return $shift->isScheduled()
            && $shift->attendance === null
            && now()->gte($shift->starts_at->copy()->subMinutes($this->int('shift_clock_in_early_minutes')))
            && now()->lt($shift->ends_at);
    }

    public function canClockOut(Shift $shift): bool
    {
        return $shift->isScheduled() && $shift->attendance?->status === AttendanceStatus::ClockedIn;
    }

    private function assertSchedulable(User $user): void
    {
        if (! $user->isStaff()) {
            throw new BusinessRuleException('Shifts can only be given to staff.', 'NOT_STAFF', 422);
        }

        $profile = $this->staff->ensureProfile($user);

        if ($profile->employment_status === EmploymentStatus::Left || ! $user->isActive()) {
            throw new BusinessRuleException($user->name.' has left or is suspended and cannot be given shifts.', 'STAFF_INACTIVE', 422);
        }
    }

    private function assertNoOverlap(User $user, CarbonImmutable $start, CarbonImmutable $end, ?string $exceptId = null): void
    {
        $clash = Shift::query()
            ->where('user_id', $user->id)
            ->where('status', ShiftStatus::Scheduled->value)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->lockForUpdate()
            ->first();

        if ($clash) {
            throw new BusinessRuleException(
                $user->name.' already has a shift then ('.ShiftNotification::describe($clash->load('department')).').',
                'SHIFT_OVERLAP',
                409,
            );
        }
    }

    /**
     * The person/date/times fields of an edit whose values differ from the shift's.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function changedTiming(Shift $shift, array $data): array
    {
        $current = [
            'user_id' => $shift->user_id,
            'date' => $shift->date->toDateString(),
            'template_id' => $shift->template_id,
            'start_time' => $this->local($shift->starts_at)->format('H:i'),
            'end_time' => $this->local($shift->ends_at)->format('H:i'),
        ];

        return array_filter(
            array_intersect_key($data, $current),
            fn ($value, string $key) => ! (in_array($key, ['start_time', 'end_time'], true) && $value === null)
                && (string) $value !== (string) $current[$key],
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Runs a shift write; a concurrent overlapping write caught by the
     * shifts_no_overlap exclusion constraint becomes the usual 409.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardOverlap(?User $user, callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === self::EXCLUSION_VIOLATION) {
                throw new BusinessRuleException(($user?->name ?? 'This person').' already has a shift then.', 'SHIFT_OVERLAP', 409);
            }

            throw $e;
        }
    }

    private function assertScheduled(Shift $shift): void
    {
        if (! $shift->isScheduled()) {
            throw new BusinessRuleException('This shift has been cancelled.', 'SHIFT_CANCELLED', 409);
        }
    }

    private function assertOwnScheduled(Shift $shift, User $user): void
    {
        abort_unless($shift->user_id === $user->id, 404);
        $this->assertScheduled($shift);
    }

    /** @return array{is_late: bool, late_minutes: int} */
    private function lateness(Shift $shift, CarbonImmutable $clockIn): array
    {
        $minutes = (int) floor($shift->starts_at->diffInMinutes($clockIn, false));
        $late = $minutes > $this->int('shift_late_grace_minutes');

        return ['is_late' => $late, 'late_minutes' => $late ? $minutes : 0];
    }

    private function parseLocal(?string $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', $value, Property::current()->timezone)
            ->setTimezone(config('app.timezone'));
    }

    private function int(string $key): int
    {
        return (int) $this->settings->get($key);
    }

    /** @return array<string, mixed> */
    private function auditValues(Shift $shift): array
    {
        return [
            'user_id' => $shift->user_id,
            'date' => $shift->date?->toDateString(),
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'department_id' => $shift->department_id,
            'location' => $shift->location,
            'notes' => $shift->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function attendanceValues(AttendanceRecord $record): array
    {
        return [
            'status' => $record->status?->value,
            'clock_in_at' => $record->clock_in_at?->toIso8601String(),
            'clock_out_at' => $record->clock_out_at?->toIso8601String(),
            'late_minutes' => $record->late_minutes,
        ];
    }
}
