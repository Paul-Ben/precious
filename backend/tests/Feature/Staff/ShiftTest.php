<?php

namespace Tests\Feature\Staff;

use App\Models\Shift;
use App\Notifications\ShiftNotification;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class ShiftTest extends StaffTestCase
{
    #[Test]
    public function a_manager_puts_someone_on_a_standard_shift_and_they_are_emailed(): void
    {
        $morning = $this->template('Morning');

        $this->actingAsUser($this->manager)
            ->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-06', 'template_id' => $morning->id, 'location' => 'Pool bar'])
            ->assertCreated()
            ->assertJsonPath('data.start_time', '07:00')
            ->assertJsonPath('data.end_time', '15:00')
            ->assertJsonPath('data.overnight', false)
            ->assertJsonPath('data.minutes', 480)
            ->assertJsonPath('data.template.name', 'Morning')
            ->assertJsonPath('data.location', 'Pool bar')
            ->assertJsonPath('data.user.name', $this->waiter->name);

        Notification::assertSentTo($this->waiter, ShiftNotification::class, fn (ShiftNotification $n) => $n->kind === 'assigned');

        $this->getJson('/api/v1/shifts?from=2026-10-05&to=2026-10-11')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/shift-templates')->assertOk()->assertJsonPath('data.0.name', 'Morning')->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_night_shift_ends_the_next_morning(): void
    {
        $id = $this->actingAsUser($this->manager)
            ->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-06', 'template_id' => $this->template('Night')->id])
            ->assertCreated()
            ->assertJsonPath('data.overnight', true)
            ->assertJsonPath('data.end_time', '07:00')
            ->json('data.id');

        $shift = Shift::findOrFail($id);
        $this->assertSame('2026-10-07 07:00', $shift->ends_at->setTimezone('Africa/Lagos')->format('Y-m-d H:i'));
        $this->assertSame(480, $shift->minutes());
    }

    #[Test]
    public function p24_one_person_cannot_hold_overlapping_shifts(): void
    {
        $this->assign($this->waiter, '2026-10-06', '07:00', '15:00');

        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-06', 'start_time' => '14:00', 'end_time' => '22:00'])
            ->assertStatus(409)->assertJsonPath('code', 'SHIFT_OVERLAP');

        // Back-to-back is fine, and so is someone else at the same time.
        $this->assign($this->waiter, '2026-10-06', '15:00', '23:00');
        $this->assign($this->staff('Bartender'), '2026-10-06', '07:00', '15:00');

        // A night shift the evening before that runs into the morning clashes too.
        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-05', 'start_time' => '23:00', 'end_time' => '08:00'])
            ->assertStatus(409);

        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-08', 'start_time' => '09:00', 'end_time' => '09:00'])->assertStatus(422);
        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-08'])->assertStatus(422);
    }

    #[Test]
    public function p27_changes_and_cancellations_are_emailed(): void
    {
        $id = $this->assign($this->waiter, '2026-10-06', '07:00', '15:00');

        $this->patchJson("/api/v1/shifts/{$id}", ['start_time' => '08:00', 'end_time' => '16:00'])
            ->assertOk()->assertJsonPath('data.start_time', '08:00');
        Notification::assertSentTo($this->waiter, ShiftNotification::class, fn (ShiftNotification $n) => $n->kind === 'changed' && str_contains((string) $n->previous, '07:00'));

        // Notes alone do not send an email.
        Notification::fake();
        $this->patchJson("/api/v1/shifts/{$id}", ['notes' => 'Bring the float'])->assertOk()->assertJsonPath('data.notes', 'Bring the float');
        Notification::assertNothingSent();

        // Giving the shift to someone else: the first person hears it is off, the second that it is theirs.
        $bartender = $this->staff('Bartender');
        $this->patchJson("/api/v1/shifts/{$id}", ['user_id' => $bartender->id])->assertOk()->assertJsonPath('data.user.id', $bartender->id);
        Notification::assertSentTo($this->waiter, ShiftNotification::class, fn (ShiftNotification $n) => $n->kind === 'cancelled');
        Notification::assertSentTo($bartender, ShiftNotification::class, fn (ShiftNotification $n) => $n->kind === 'assigned');

        $this->postJson("/api/v1/shifts/{$id}/cancel", ['reason' => 'Quiet day'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        Notification::assertSentTo($bartender, ShiftNotification::class, fn (ShiftNotification $n) => $n->kind === 'cancelled');
        $this->postJson("/api/v1/shifts/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'SHIFT_CANCELLED');
    }

    #[Test]
    public function a_shift_that_has_started_keeps_its_times(): void
    {
        $id = $this->assign($this->waiter, '2026-10-05', '07:00', '15:00');
        Notification::assertNothingSentTo($this->waiter); // already under way: no email

        $this->patchJson("/api/v1/shifts/{$id}", ['end_time' => '16:00'])->assertStatus(409)->assertJsonPath('code', 'SHIFT_STARTED');
        $this->postJson("/api/v1/shifts/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'SHIFT_STARTED');
        $this->patchJson("/api/v1/shifts/{$id}", ['location' => 'Lobby bar'])->assertOk();
    }

    #[Test]
    public function p24_a_week_is_copied_skipping_clashes(): void
    {
        $this->assign($this->waiter, '2026-10-06', '07:00', '15:00');
        $this->assign($this->waiter, '2026-10-09', '15:00', '23:00', ['location' => 'Terrace']);
        $this->assign($this->waiter, '2026-10-13', '06:00', '08:00'); // will clash with the copy of the 6th

        $this->postJson('/api/v1/shifts/copy-week', ['from_week' => '2026-10-05', 'to_week' => '2026-10-12'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonCount(1, 'data.skipped');

        $this->assertTrue(Shift::where('date', '2026-10-16')->where('location', 'Terrace')->exists());

        $this->postJson('/api/v1/shifts/copy-week', ['from_week' => '2026-10-06', 'to_week' => '2026-10-12'])->assertStatus(422);
        $this->postJson('/api/v1/shifts/copy-week', ['from_week' => '2026-10-05', 'to_week' => '2026-09-28'])->assertStatus(422);
    }

    #[Test]
    public function only_schedulers_change_the_rota(): void
    {
        $this->actingAsUser($this->waiter);
        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-06', 'start_time' => '07:00', 'end_time' => '15:00'])->assertForbidden();
        $this->getJson('/api/v1/shifts?from=2026-10-05&to=2026-10-11')->assertForbidden();
        $this->postJson('/api/v1/shift-templates', ['name' => 'Split', 'start_time' => '10:00', 'end_time' => '14:00'])->assertForbidden();

        $this->actingAsUser($this->manager)
            ->postJson('/api/v1/shift-templates', ['name' => 'Split', 'start_time' => '10:00', 'end_time' => '14:00'])
            ->assertCreated()->assertJsonPath('data.overnight', false);
        $this->postJson('/api/v1/shift-templates', ['name' => 'Split', 'start_time' => '11:00', 'end_time' => '15:00'])->assertStatus(422);

        // Customers cannot be put on the rota.
        $this->postJson('/api/v1/shifts', ['user_id' => $this->customer()->id, 'date' => '2026-10-06', 'start_time' => '07:00', 'end_time' => '15:00'])
            ->assertStatus(422)->assertJsonPath('code', 'NOT_STAFF');
    }
}
