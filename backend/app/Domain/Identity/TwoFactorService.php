<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Email one-time-code 2FA.
 *
 * Codes are 6 digits, stored hashed, single-use, expire after
 * security.two_factor.code_ttl_minutes and lock after max_attempts failures.
 */
class TwoFactorService
{
    public function __construct(
        private readonly Request $request,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{challenge_id: string, channel: string, destination: string, expires_at: string}
     */
    public function createChallenge(User $user): array
    {
        return DB::transaction(function () use ($user) {
            // Only one live challenge per user.
            TwoFactorChallenge::where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $code = $this->generateCode();

            $challenge = TwoFactorChallenge::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'purpose' => 'login',
                'channel' => 'email',
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttl()),
                'last_sent_at' => now(),
                'ip_address' => $this->request->ip(),
                'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
            ]);

            $this->send($user, $code);
            $this->audit->record('auth.two_factor.challenge_sent', $user, metadata: ['channel' => 'email'], actor: $user);

            return $this->describe($challenge, $user);
        });
    }

    /**
     * Validates the code and consumes the challenge. Returns the user.
     */
    public function verify(string $challengeId, string $code): User
    {
        $result = DB::transaction(function () use ($challengeId, $code) {
            /** @var TwoFactorChallenge|null $challenge */
            $challenge = TwoFactorChallenge::whereKey($challengeId)->lockForUpdate()->first();

            if (! $challenge || ! $challenge->isUsable()) {
                return ['error' => 'expired'];
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');
                $remaining = max(0, $this->maxAttempts() - $challenge->attempts);

                return ['error' => 'mismatch', 'challenge' => $challenge, 'remaining' => $remaining];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['user' => $challenge->user];
        });

        // Failures are recorded outside the transaction so they are kept.
        if (($result['error'] ?? null) === 'expired') {
            throw new BusinessRuleException(
                'This verification code has expired or is no longer valid. Please sign in again.',
                'TWO_FACTOR_EXPIRED'
            );
        }

        if (($result['error'] ?? null) === 'mismatch') {
            $this->audit->record('auth.two_factor.failed', $result['challenge']->user, metadata: [
                'remaining_attempts' => $result['remaining'],
            ], actor: $result['challenge']->user);

            throw new BusinessRuleException(
                $result['remaining'] > 0
                    ? 'The verification code is incorrect.'
                    : 'Too many incorrect attempts. Please sign in again.',
                $result['remaining'] > 0 ? 'TWO_FACTOR_INVALID' : 'TWO_FACTOR_LOCKED',
                422,
                ['remaining_attempts' => $result['remaining']]
            );
        }

        /** @var User $user */
        $user = $result['user'];

        if (! $user->isActive()) {
            throw new BusinessRuleException('Your account has been suspended.', 'ACCOUNT_SUSPENDED', 403);
        }

        $this->audit->record('auth.two_factor.verified', $user, actor: $user);

        return $user;
    }

    /**
     * Sends a fresh code for an existing live challenge.
     *
     * @return array{challenge_id: string, channel: string, destination: string, expires_at: string}
     */
    public function resend(string $challengeId): array
    {
        return DB::transaction(function () use ($challengeId) {
            /** @var TwoFactorChallenge|null $challenge */
            $challenge = TwoFactorChallenge::whereKey($challengeId)->lockForUpdate()->first();

            if (! $challenge || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                throw new BusinessRuleException(
                    'This sign-in attempt has expired. Please sign in again.',
                    'TWO_FACTOR_EXPIRED'
                );
            }

            $cooldown = (int) config('security.two_factor.resend_cooldown_seconds', 60);
            $wait = $cooldown - (int) $challenge->last_sent_at->diffInSeconds(now(), true);

            if ($wait > 0) {
                throw new BusinessRuleException(
                    "Please wait {$wait} seconds before requesting another code.",
                    'TWO_FACTOR_COOLDOWN',
                    429,
                    ['retry_after' => $wait]
                );
            }

            $code = $this->generateCode();

            $challenge->forceFill([
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttl()),
                'last_sent_at' => now(),
            ])->save();

            $this->send($challenge->user, $code);

            return $this->describe($challenge, $challenge->user);
        });
    }

    private function send(User $user, string $code): void
    {
        // Sent immediately (not queued) so the one-time code never sits in the
        // jobs table and users are not blocked when no worker is running.
        $user->notifyNow(new TwoFactorCodeNotification($code, $this->ttl()));
    }

    private function generateCode(): string
    {
        $length = (int) config('security.two_factor.code_length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function ttl(): int
    {
        return (int) config('security.two_factor.code_ttl_minutes', 10);
    }

    private function maxAttempts(): int
    {
        return (int) config('security.two_factor.max_attempts', 5);
    }

    /**
     * @return array{challenge_id: string, channel: string, destination: string, expires_at: string}
     */
    private function describe(TwoFactorChallenge $challenge, User $user): array
    {
        return [
            'challenge_id' => $challenge->id,
            'channel' => 'email',
            'destination' => self::maskEmail($user->email),
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }
}
