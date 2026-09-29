<?php

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * @return array{0: string, 1: string} [challenge id, code]
     */
    private function startLogin(User $user): array
    {
        $response = $this->postJson('/api/v1/auth/staff/login', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('data.two_factor_required', true)
            ->assertJsonMissingPath('data.token');

        $code = null;
        Notification::assertSentTo($user, TwoFactorCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return [$response->json('data.two_factor.challenge_id'), $code];
    }

    #[Test]
    public function administrators_must_complete_email_two_factor_before_getting_a_token(): void
    {
        $admin = $this->staff('Administrator');

        [$challengeId, $code] = $this->startLogin($admin);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $response = $this->postJson('/api/v1/auth/two-factor/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.two_factor_required', true);

        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertTrue($token->two_factor_confirmed);
    }

    #[Test]
    public function super_administrators_also_require_two_factor(): void
    {
        $this->startLogin($this->staff('Super Administrator'));
    }

    #[Test]
    public function non_admin_staff_log_in_without_two_factor_unless_they_opt_in(): void
    {
        $waiter = $this->staff('Waiter');

        $this->postJson('/api/v1/auth/staff/login', ['email' => $waiter->email, 'password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.two_factor_required', false);

        $waiter->forceFill(['two_factor_enabled' => true])->save();

        $this->startLogin($waiter);
    }

    #[Test]
    public function codes_are_single_use(): void
    {
        [$challengeId, $code] = $this->startLogin($this->staff('Administrator'));

        $this->postJson('/api/v1/auth/two-factor/verify', compact('code') + ['challenge_id' => $challengeId])->assertOk();

        $this->postJson('/api/v1/auth/two-factor/verify', compact('code') + ['challenge_id' => $challengeId])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_EXPIRED');
    }

    #[Test]
    public function codes_expire(): void
    {
        [$challengeId, $code] = $this->startLogin($this->staff('Administrator'));

        $this->travel(11)->minutes();

        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_EXPIRED');
    }

    #[Test]
    public function the_challenge_locks_after_too_many_wrong_codes(): void
    {
        [$challengeId, $code] = $this->startLogin($this->staff('Administrator'));
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_id' => $challengeId, 'code' => $wrong])
                ->assertStatus(422)
                ->assertJsonPath('code', 'TWO_FACTOR_INVALID')
                ->assertJsonPath('remaining_attempts', 5 - $i);
        }

        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_id' => $challengeId, 'code' => $wrong])
            ->assertJsonPath('code', 'TWO_FACTOR_LOCKED');

        // Even the correct code no longer works.
        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TWO_FACTOR_EXPIRED');
    }

    #[Test]
    public function codes_are_stored_hashed(): void
    {
        [$challengeId, $code] = $this->startLogin($this->staff('Administrator'));

        $this->assertNotSame($code, TwoFactorChallenge::find($challengeId)->code_hash);
    }

    #[Test]
    public function resending_respects_a_cooldown_and_invalidates_the_old_code(): void
    {
        $admin = $this->staff('Administrator');
        [$challengeId, $oldCode] = $this->startLogin($admin);

        $this->postJson('/api/v1/auth/two-factor/resend', ['challenge_id' => $challengeId])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TWO_FACTOR_COOLDOWN');

        $this->travel(61)->seconds();

        $this->postJson('/api/v1/auth/two-factor/resend', ['challenge_id' => $challengeId])->assertOk();

        Notification::assertSentToTimes($admin, TwoFactorCodeNotification::class, 2);

        $this->postJson('/api/v1/auth/two-factor/verify', ['challenge_id' => $challengeId, 'code' => $oldCode])
            ->assertStatus(422);
    }

    #[Test]
    public function an_admin_token_issued_without_two_factor_is_rejected(): void
    {
        $admin = $this->staff('Administrator');

        $this->actingAsUser($admin, twoFactorConfirmed: false)
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
    }

    #[Test]
    public function granting_an_admin_role_revokes_tokens_that_skipped_two_factor(): void
    {
        $waiter = $this->staff('Waiter');
        $this->issueToken($waiter, false);

        $super = $this->staff('Super Administrator');
        $this->actingAsUser($super)
            ->putJson("/api/v1/users/{$waiter->id}/roles", ['roles' => ['Waiter', 'Administrator']])
            ->assertOk();

        $this->assertSame(0, $waiter->tokens()->where('two_factor_confirmed', false)->count());
    }
}
