<?php

namespace Tests;

use App\Models\PersonalAccessToken;
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
}
