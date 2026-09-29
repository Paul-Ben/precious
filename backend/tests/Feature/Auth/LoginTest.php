<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginTest extends TestCase
{
    #[Test]
    public function a_customer_can_log_in(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', false)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['permissions']]]);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists());
    }

    #[Test]
    public function a_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertTrue(AuditLog::where('action', 'auth.login_failed')->where('auditable_id', $user->id)->exists());
    }

    #[Test]
    public function unknown_accounts_get_the_same_error_as_wrong_passwords(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    #[Test]
    public function staff_must_use_the_staff_login_and_customers_the_customer_login(): void
    {
        $staff = $this->staff('Waiter');
        $customer = $this->customer();

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => UserFactory::PASSWORD])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/staff/login', ['email' => $customer->email, 'password' => UserFactory::PASSWORD])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/staff/login', ['email' => $staff->email, 'password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.type', 'staff')
            ->assertJsonPath('data.user.roles', ['Waiter']);
    }

    #[Test]
    public function suspended_accounts_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }

    #[Test]
    public function logout_revokes_the_current_token(): void
    {
        $user = $this->customer();
        $token = $this->issueToken($user);

        $this->withBearer($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertSame(0, $user->tokens()->count());

        $this->withBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function expired_tokens_are_rejected(): void
    {
        $user = $this->customer();
        $token = $this->issueToken($user);

        $this->travel(2)->hours();

        $this->withBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        $user = $this->customer();

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
