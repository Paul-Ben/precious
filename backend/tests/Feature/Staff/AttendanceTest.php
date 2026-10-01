<?php

namespace Tests\Feature\Staff;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\Test;

class AttendanceTest extends StaffTestCase
{
    #[Test]
    public function p25_staff_clock_in_from_thirty_minutes_before_and_out_again(): void
    {
        $id = $this->assign($this->waiter, '2026-10-05', '11:00', '19:00');

        $this->actingAsUser($this->waiter)
            ->postJson("/api/v1/my-shifts/{$id}/clock-in")
            ->assertStatus(409)->assertJsonPath('code', 'CLOCK_IN_TOO_EARLY');

        $this->at('10:35', $this->waiter);
        $this->getJson('/api/v1/my-shifts')->assertOk()->assertJsonPath('data.0.can_clock_in', true);
        $this->postJson("/api/v1/my-shifts/{$id}/clock-in")
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'CLOCKED_IN')
            ->assertJsonPath('data.attendance.is_late', false)
            ->assertJsonPath('data.can_clock_out', true);
        $this->postJson("/api/v1/my-shifts/{$id}/clock-in")->assertStatus(409)->assertJsonPath('code', 'ALREADY_CLOCKED_IN');

        $this->at('19:05', $this->waiter);
        $this->postJson("/api/v1/my-shifts/{$id}/clock-out")
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'CLOCKED_OUT')
            ->assertJsonPath('data.attendance.worked_minutes', 510);
        $this->postJson("/api/v1/my-shifts/{$id}/clock-out")->assertStatus(409)->assertJsonPath('code', 'NOT_CLOCKED_IN');
    }

    #[Test]
    public function p25_clocking_in_more_than_ten_minutes_late_is_late(): void
    {
        $late = $this->assign($this->waiter, '2026-10-05', '09:30', '12:00');
        $bartender = $this->staff('Bartender');
        $onTime = $this->assign($bartender, '2026-10-05', '09:52', '12:00');

        $this->actingAsUser($this->waiter)->postJson("/api/v1/my-shifts/{$late}/clock-in")
            ->assertOk()->assertJsonPath('data.attendance.is_late', true)->assertJsonPath('data.attendance.late_minutes', 30);

        $this->actingAsUser($bartender)->postJson("/api/v1/my-shifts/{$onTime}/clock-in")
            ->assertOk()->assertJsonPath('data.attendance.is_late', false)->assertJsonPath('data.attendance.late_minutes', 0);

        // Nobody clocks in on someone else's shift.
        $this->postJson("/api/v1/my-shifts/{$late}/clock-out")->assertNotFound();
    }

    #[Test]
    public function p28_my_shifts_show_who_else_is_working(): void
    {
        $bartender = $this->staff('Bartender');
        $this->assign($this->waiter, '2026-10-06', '15:00', '23:00');
        $this->assign($bartender, '2026-10-06', '18:00', '02:00', ['location' => 'Main bar']);
        $this->assign($this->staff('Receptionist'), '2026-10-06', '07:00', '15:00'); // back-to-back: not "with" the waiter

        $this->actingAsUser($this->waiter)->getJson('/api/v1/my-shifts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.colleagues')
            ->assertJsonPath('data.0.colleagues.0.name', $bartender->name)
            ->assertJsonPath('data.0.colleagues.0.location', 'Main bar')
            ->assertJsonPath('data.0.can_clock_in', false);
    }

    #[Test]
    public function p26_no_show_is_absent_and_a_forgotten_clock_out_is_closed_and_flagged(): void
    {
        $missed = $this->assign($this->waiter, '2026-10-05', '10:00', '12:00');
        $bartender = $this->staff('Bartender');
        $forgot = $this->assign($bartender, '2026-10-05', '10:00', '12:00');
        $this->actingAsUser($bartender)->postJson("/api/v1/my-shifts/{$forgot}/clock-in")->assertOk();

        // Shift over: absence is recorded; the open clock-in waits a few hours in case of overtime.
        $this->at('12:01');
        $this->artisan('shifts:close-attendance')->assertSuccessful();
        $this->assertSame('ABSENT', AttendanceRecord::where('shift_id', $missed)->value('status')->value);
        $this->assertSame('CLOCKED_IN', AttendanceRecord::where('shift_id', $forgot)->value('status')->value);

        $this->at('16:30');
        $this->artisan('shifts:close-attendance')->assertSuccessful();
        $record = AttendanceRecord::where('shift_id', $forgot)->firstOrFail();
        $this->assertSame('CLOCKED_OUT', $record->status->value);
        $this->assertTrue($record->auto_closed && $record->needs_review);
        $this->assertSame(120, $record->workedMinutes());

        $this->actingAsUser($this->manager)
            ->getJson('/api/v1/attendance?from=2026-10-05&to=2026-10-05&exceptions=1')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    #[Test]
    public function p25_managers_correct_attendance_with_a_reason(): void
    {
        $id = $this->assign($this->waiter, '2026-10-05', '08:00', '16:00');
        $future = $this->assign($this->waiter, '2026-10-06', '08:00', '16:00');

        $this->putJson("/api/v1/shifts/{$id}/attendance", ['clock_in_at' => '2026-10-05 08:20'])->assertStatus(422);
        $this->putJson("/api/v1/shifts/{$future}/attendance", ['absent' => true, 'reason' => 'Called in sick'])
            ->assertStatus(409)->assertJsonPath('code', 'SHIFT_NOT_STARTED');

        $this->putJson("/api/v1/shifts/{$id}/attendance", ['clock_in_at' => '2026-10-05 08:20', 'reason' => 'Forgot to clock in; seen by the manager'])
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'CLOCKED_IN')
            ->assertJsonPath('data.attendance.late_minutes', 20)
            ->assertJsonPath('data.attendance.corrected_by', $this->manager->name);

        $this->putJson("/api/v1/shifts/{$id}/attendance", ['clock_out_at' => '2026-10-05 07:00', 'reason' => 'typo'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_TIMES');
        $this->putJson("/api/v1/shifts/{$id}/attendance", ['clock_out_at' => '2026-10-05 23:00', 'reason' => 'future'])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_TIMES');

        $this->assertTrue(AuditLog::where('action', 'attendance.corrected')->exists());
        $this->actingAsUser($this->waiter)->putJson("/api/v1/shifts/{$id}/attendance", ['absent' => true, 'reason' => 'nope'])->assertForbidden();
    }

    #[Test]
    public function the_attendance_summary_and_export_add_up_hours(): void
    {
        $worked = $this->assign($this->waiter, '2026-10-05', '06:00', '09:00');
        $this->assign($this->waiter, '2026-10-05', '09:30', '09:45');
        $this->assign($this->waiter, '2026-10-07', '08:00', '16:00'); // not started: not counted

        $this->putJson("/api/v1/shifts/{$worked}/attendance", ['clock_in_at' => '2026-10-05 06:15', 'clock_out_at' => '2026-10-05 09:00', 'reason' => 'Paper sign-in sheet'])->assertOk();
        $this->artisan('shifts:close-attendance')->assertSuccessful(); // 09:30 shift ended unattended → absent

        $this->getJson('/api/v1/attendance/summary?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.people.0.name', $this->waiter->name)
            ->assertJsonPath('data.people.0.shifts', 2)
            ->assertJsonPath('data.people.0.scheduled_minutes', 195)
            ->assertJsonPath('data.people.0.worked_minutes', 165)
            ->assertJsonPath('data.people.0.late', 1)
            ->assertJsonPath('data.people.0.absent', 1)
            ->assertJsonPath('data.totals.absent', 1);

        $csv = $this->get('/api/v1/attendance/export?from=2026-10-01&to=2026-10-31')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $lines = array_values(array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Late', $lines[1]);
        $this->assertStringContainsString('2.75', $lines[1]);
        $this->assertStringContainsString('Absent', $lines[2]);
    }
}
