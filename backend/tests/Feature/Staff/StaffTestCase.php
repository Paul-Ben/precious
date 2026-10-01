<?php

namespace Tests\Feature\Staff;

use App\Models\ShiftTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

abstract class StaffTestCase extends TestCase
{
    protected User $manager;

    protected User $waiter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        // Monday 5 Oct 2026, 10:00 hotel time.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'));
        $this->manager = $this->staff('Hotel Manager');
        $this->waiter = $this->staff('Waiter');
    }

    protected function template(string $name): ShiftTemplate
    {
        return ShiftTemplate::query()->where('name', $name)->firstOrFail();
    }

    /** Adds a shift through the API as the manager and returns its id. */
    protected function assign(User $user, string $date, string $start, string $end, array $extra = []): string
    {
        return $this->actingAsUser($this->manager)
            ->postJson('/api/v1/shifts', ['user_id' => $user->id, 'date' => $date, 'start_time' => $start, 'end_time' => $end, ...$extra])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Moves the clock to a time on 5 Oct 2026 in hotel time. Test tokens last an
     * hour, so pass the user to sign in again after the jump.
     */
    protected function at(string $time, ?User $as = null): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", 'Africa/Lagos'));

        if ($as) {
            $this->actingAsUser($as);
        }
    }
}
