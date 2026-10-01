<?php

namespace Tests\Feature\Staff;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Shift;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class StaffRecordsTest extends StaffTestCase
{
    #[Test]
    public function every_staff_member_has_a_record_with_an_employee_number(): void
    {
        $list = $this->actingAsUser($this->manager)->getJson('/api/v1/staff?per_page=50')->assertOk()->json('data');
        $numbers = collect($list)->pluck('employee_number');

        $this->assertCount(2, $list);
        $this->assertTrue($numbers->every(fn ($n) => preg_match('/^EMP-\d{4}$/', $n) === 1));
        $this->assertSame($numbers->count(), $numbers->unique()->count());

        // Accounts created in Users get their record straight away.
        $id = $this->actingAsUser($this->staff('Administrator'))
            ->postJson('/api/v1/users', ['name' => 'Tola Bello', 'email' => 'tola@example.com', 'roles' => ['Receptionist']])
            ->assertCreated()->json('data.id');
        $this->actingAsUser($this->manager)->getJson("/api/v1/staff/{$id}")
            ->assertOk()->assertJsonPath('data.employment_status', 'ACTIVE')
            ->assertJsonPath('data.employee_number', 'EMP-0003');
    }

    #[Test]
    public function managers_edit_staff_records(): void
    {
        $bar = Department::where('code', 'BAR')->firstOrFail();

        $this->actingAsUser($this->manager)
            ->patchJson("/api/v1/staff/{$this->waiter->id}", [
                'department_id' => $bar->id,
                'position' => 'Senior waiter',
                'start_date' => '2025-03-01',
                'emergency_contact_name' => 'Ngozi',
                'emergency_contact_phone' => '+234 803 000 0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.department.name', 'Bar')
            ->assertJsonPath('data.position', 'Senior waiter')
            ->assertJsonPath('data.start_date', '2025-03-01');

        $this->assertTrue(AuditLog::where('action', 'staff.updated')->exists());
        $this->getJson('/api/v1/staff?department_id='.$bar->id)->assertJsonCount(1, 'data');
        $this->patchJson("/api/v1/staff/{$this->waiter->id}", ['employment_status' => 'RETIRED'])->assertStatus(422);
    }

    #[Test]
    public function p23_someone_who_left_cannot_sign_in_and_loses_future_shifts(): void
    {
        $past = $this->assign($this->waiter, '2026-10-05', '07:00', '09:00');
        $future = $this->assign($this->waiter, '2026-10-07', '07:00', '15:00');

        $this->actingAsUser($this->manager)
            ->patchJson("/api/v1/staff/{$this->waiter->id}", ['employment_status' => 'LEFT'])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'LEFT')
            ->assertJsonPath('data.end_date', '2026-10-05')
            ->assertJsonPath('data.account_status', 'suspended');

        $this->assertSame('CANCELLED', Shift::find($future)->status->value);
        $this->assertSame('SCHEDULED', Shift::find($past)->status->value);

        $this->postJson('/api/v1/shifts', ['user_id' => $this->waiter->id, 'date' => '2026-10-08', 'start_time' => '07:00', 'end_time' => '15:00'])
            ->assertStatus(422)->assertJsonPath('code', 'STAFF_INACTIVE');
    }

    #[Test]
    public function staff_photos_are_private_files(): void
    {
        Storage::fake('local');

        $this->actingAsUser($this->manager)
            ->post("/api/v1/staff/{$this->waiter->id}/photo", ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])
            ->assertOk()->assertJsonPath('data.has_photo', true);

        $this->get("/api/v1/staff/{$this->waiter->id}/photo")->assertOk();
        $this->actingAsUser($this->staff('Receptionist'))->get("/api/v1/staff/{$this->waiter->id}/photo")->assertForbidden();

        $this->actingAsUser($this->manager)->deleteJson("/api/v1/staff/{$this->waiter->id}/photo")->assertOk();
        $this->get("/api/v1/staff/{$this->waiter->id}/photo")->assertNotFound();
    }

    #[Test]
    public function staff_records_follow_permissions(): void
    {
        $this->actingAsUser($this->waiter)->getJson('/api/v1/staff')->assertForbidden();

        $this->actingAsUser($this->staff('Auditor'));
        $this->getJson('/api/v1/staff')->assertOk();
        $this->patchJson("/api/v1/staff/{$this->waiter->id}", ['position' => 'X'])->assertForbidden();
    }
}
