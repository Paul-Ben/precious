<?php

namespace Tests;

use App\Models\PaymentGatewaySetting;
use App\Models\PersonalAccessToken;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seed permissions, system roles and gateway rows before each test. */
    protected $seed = true;

    protected $seeder = CoreSeeder::class;

    protected function staff(string ...$roles): User
    {
        return User::factory()->withRoles(...$roles)->create();
    }

    protected function customer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Customer');

        return $user;
    }

    /**
     * Authenticate subsequent requests with a real Sanctum token.
     * By default the token counts as 2FA-confirmed when the user requires 2FA.
     */
    protected function actingAsUser(User $user, ?bool $twoFactorConfirmed = null): static
    {
        $token = $this->issueToken($user, $twoFactorConfirmed);

        return $this->withBearer($token);
    }

    protected function issueToken(User $user, ?bool $twoFactorConfirmed = null): string
    {
        $new = $user->createToken('test', ['*'], now()->addHour());

        /** @var PersonalAccessToken $model */
        $model = $new->accessToken;
        $model->forceFill(['two_factor_confirmed' => $twoFactorConfirmed ?? $user->requiresTwoFactor()])->save();

        return $new->plainTextToken;
    }

    /**
     * Switch the bearer token. Auth guards cache the resolved user inside a
     * single test, so they are reset whenever the token changes.
     */
    protected function withBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    protected function withoutBearer(): static
    {
        $this->app['auth']->forgetGuards();
        $this->withoutToken();

        return $this;
    }

    /**
     * A room type with the given room numbers, e.g. roomType('Deluxe', '50000.00', ['201', '202']).
     *
     * @param  list<string>  $numbers
     */
    protected function roomType(string $name = 'Deluxe', string $rate = '50000.00', array $numbers = ['201'], array $attributes = []): RoomType
    {
        $property = Property::current();

        $type = RoomType::create([
            'property_id' => $property->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'base_rate' => $rate,
            'max_adults' => 2,
            'max_children' => 1,
            'max_occupancy' => 3,
            ...$attributes,
        ]);

        foreach ($numbers as $number) {
            Room::create(['property_id' => $property->id, 'room_type_id' => $type->id, 'number' => $number]);
        }

        return $type;
    }

    /**
     * Payload for POST /public/reservations.
     *
     * @return array<string, mixed>
     */
    protected function bookingPayload(RoomType $type, string $checkIn, string $checkOut, array $overrides = []): array
    {
        return array_replace_recursive([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 2,
            'children' => 0,
            'rooms' => [['room_type_id' => $type->id, 'quantity' => 1]],
            'guest' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'phone' => '+2348012345678',
            ],
            'accept_terms' => true,
        ], $overrides);
    }

    /**
     * Books a room through the public API.
     *
     * @return array{0: Reservation, 1: string} reservation and guest lookup token
     */
    protected function bookOnline(RoomType $type, int $fromDay = 10, int $nights = 3, array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/public/reservations', $this->bookingPayload($type, $this->day($fromDay), $this->day($fromDay + $nights), $overrides))
            ->assertCreated();

        return [Reservation::where('number', $response->json('data.reservation.number'))->firstOrFail(), $response->json('data.lookup_token')];
    }

    /**
     * A CONFIRMED booking starting $fromDay days from today, fully paid or
     * with only the deposit paid.
     */
    protected function confirmedBooking(RoomType $type, int $fromDay = 0, int $nights = 2, bool $fullyPaid = true, array $overrides = []): Reservation
    {
        [$reservation] = $this->bookOnline($type, $fromDay, $nights, $overrides);

        $reservation->forceFill([
            'status' => 'CONFIRMED',
            'amount_paid' => $fullyPaid ? $reservation->total : $reservation->deposit_amount,
            'payment_status' => $fullyPaid ? 'PAID' : 'DEPOSIT_PAID',
            'confirmed_at' => now(),
            'expires_at' => null,
        ])->save();

        return $reservation->refresh();
    }

    /** Checks a confirmed booking in through the API as a receptionist. */
    protected function checkIn(Reservation $reservation, array $payload = []): Reservation
    {
        $this->actingAsUser($this->staff('Receptionist'))
            ->postJson("/api/v1/reservations/{$reservation->id}/check-in", $payload + ['id_type' => 'NATIONAL_ID', 'id_number' => '12345678901'])
            ->assertOk();

        return $reservation->refresh();
    }

    /** Enables a gateway in test mode with dummy credentials. */
    protected function enableGateway(string $gateway = 'paystack', bool $default = true): PaymentGatewaySetting
    {
        $credentials = match ($gateway) {
            'paystack' => ['test_public_key' => 'pk_test_public', 'test_secret_key' => 'sk_test_secret'],
            'flutterwave' => [
                'test_public_key' => 'FLWPUBK_TEST-public',
                'test_secret_key' => 'FLWSECK_TEST-secret',
                'test_encryption_key' => 'FLWSECK_TESTenc',
                'test_webhook_secret_hash' => 'flw-hash-123',
            ],
        };

        if ($default) {
            PaymentGatewaySetting::query()->update(['is_default' => false]);
        }

        $setting = PaymentGatewaySetting::where('gateway', $gateway)->firstOrFail();
        $setting->forceFill(['is_enabled' => true, 'is_default' => $default, 'mode' => 'test', 'credentials' => $credentials])->save();

        return $setting;
    }

    /** A date N days from today in the hotel's time zone (Y-m-d). */
    protected function day(int $offset): string
    {
        return now('Africa/Lagos')->startOfDay()->addDays($offset)->toDateString();
    }
}
