<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    private const NEW_PASSWORD = 'N3w-Strong-Pass!';

    #[Test]
    public function staff_with_a_temporary_password_must_change_it_before_doing_anything_else(): void
    {
        $manager = User::factory()->withRoles('Hotel Manager')->mustChangePassword()->create();
        $token = $this->issueToken($manager);

        $this->withBearer($token)
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        // /auth/me still works so the frontend can redirect.
        $this->withBearer($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);

        $this->withBearer($token)->postJson('/api/v1/auth/password/change', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->withBearer($token)->getJson('/api/v1/users')->assertOk();
    }

    #[Test]
    public function changing_password_requires_the_current_password_and_signs_out_other_sessions(): void
    {
        $user = $this->customer();
        $other = $this->issueToken($user);
        $current = $this->issueToken($user);

        $this->withBearer($current)->postJson('/api/v1/auth/password/change', [
            'current_password' => 'wrong',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withBearer($current)->postJson('/api/v1/auth/password/change', [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->withBearer($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withBearer($current)->getJson('/api/v1/auth/me')->assertOk();
    }

    #[Test]
    public function forgot_password_sends_a_link_to_the_frontend_without_revealing_accounts(): void
    {
        Notification::fake();
        $user = $this->customer();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('security.frontend_url').'/reset-password?');
        });
    }

    #[Test]
    public function a_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->withRoles('Waiter')->mustChangePassword()->create();
        $this->issueToken($user);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function an_invalid_reset_token_is_rejected(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
