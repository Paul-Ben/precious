<?php

namespace Tests\Feature\Hotel;

use App\Models\AuditLog;
use App\Models\Reservation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PropertySettingsTest extends TestCase
{
    #[Test]
    public function the_confirmed_default_policies_are_in_place(): void
    {
        $this->getJson('/api/v1/public/property')
            ->assertOk()
            ->assertJsonPath('data.name', 'Precious Hotel & Bar')
            ->assertJsonPath('data.policies.deposit_percent', '30')
            ->assertJsonPath('data.policies.hold_minutes', 30)
            ->assertJsonPath('data.policies.check_in_time', '14:00')
            ->assertJsonPath('data.policies.check_out_time', '12:00')
            ->assertJsonPath('data.policies.free_cancellation_hours', 48)
            ->assertJsonPath('data.policies.vat_percent', '7.5')
            ->assertJsonMissingPath('data.policies.staff_hold_max_minutes');
    }

    #[Test]
    public function an_admin_changes_policies_which_apply_to_new_bookings_only(): void
    {
        $type = $this->roomType('Deluxe', '50000.00', ['201', '202']);
        $before = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(2)))->json('data.reservation.id');

        $this->actingAsUser($this->staff('Administrator'))
            ->patchJson('/api/v1/property', [
                'property' => ['phone' => '+2348000000000'],
                'policies' => ['deposit_percent' => '50', 'check_out_time' => '11:00'],
            ])->assertOk()
            ->assertJsonPath('data.phone', '+2348000000000')
            ->assertJsonPath('data.policies.deposit_percent', '50');

        $this->assertTrue(AuditLog::where('action', 'settings.hotel_policies_updated')->exists());

        $this->withoutBearer();
        $after = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day(1), $this->day(2), [
            'guest' => ['email' => 'x@example.com'],
        ]))->json('data.reservation.id');

        $this->assertSame('30.00', Reservation::find($before)->deposit_percent);
        $this->assertSame('50.00', Reservation::find($after)->deposit_percent);
    }

    #[Test]
    public function managers_can_view_but_not_change_settings(): void
    {
        $this->actingAsUser($this->staff('Hotel Manager'))->getJson('/api/v1/property')->assertOk();
        $this->patchJson('/api/v1/property', ['policies' => ['deposit_percent' => '10']])->assertForbidden();
    }

    #[Test]
    public function invalid_policy_values_are_rejected(): void
    {
        $this->actingAsUser($this->staff('Administrator'))
            ->patchJson('/api/v1/property', ['policies' => ['check_in_time' => '25:00', 'deposit_percent' => '150']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['policies.check_in_time', 'policies.deposit_percent']);
    }
}
