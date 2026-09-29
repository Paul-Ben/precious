<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditService;
use App\Domain\Identity\AuthService;
use App\Domain\Identity\TwoFactorService;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\TwoFactorResendRequest;
use App\Http\Requests\Auth\TwoFactorVerifyRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditService $audit,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->registerCustomer($request->validated());

        return ApiResponse::created(
            $this->authPayload($result['user'], $result['token']),
            'Your account has been created.'
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->handleLogin($request, UserType::Customer);
    }

    public function staffLogin(LoginRequest $request): JsonResponse
    {
        return $this->handleLogin($request, UserType::Staff);
    }

    public function verifyTwoFactor(TwoFactorVerifyRequest $request): JsonResponse
    {
        $result = $this->auth->completeTwoFactorLogin($request->validated('challenge_id'), $request->validated('code'));

        return ApiResponse::success($this->authPayload($result['user'], $result['token']), 'Signed in successfully.');
    }

    public function resendTwoFactor(TwoFactorResendRequest $request): JsonResponse
    {
        $challenge = $this->twoFactor->resend($request->validated('challenge_id'));

        return ApiResponse::success([
            'two_factor_required' => true,
            'two_factor' => $challenge,
        ], 'A new verification code has been sent.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            (new UserResource($request->user()->load('roles')))->withPermissions()
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return ApiResponse::success(null, 'Signed out.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->auth->changePassword($user, $request->validated('current_password'), $request->validated('password'));

        return ApiResponse::success(
            (new UserResource($user->refresh()->load('roles')))->withPermissions(),
            'Your password has been changed.'
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(['email' => $request->validated('email')]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit->record('auth.password_reset_requested', metadata: ['email' => $request->validated('email')]);
        }

        // Same response whether or not the account exists (no user enumeration).
        return ApiResponse::success(null, 'If an account exists for that email, a password reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
                $this->audit->record('auth.password_reset', $user, actor: $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        return ApiResponse::success(null, 'Your password has been reset. You can now sign in.');
    }

    private function handleLogin(LoginRequest $request, UserType $type): JsonResponse
    {
        $result = $this->auth->login($request->validated('email'), $request->validated('password'), $type);

        if (isset($result['two_factor'])) {
            return ApiResponse::success([
                'two_factor_required' => true,
                'two_factor' => $result['two_factor'],
            ], 'Enter the verification code we emailed you.');
        }

        return ApiResponse::success($this->authPayload($result['user'], $result['token']), 'Signed in successfully.');
    }

    private function authPayload(User $user, array $token): array
    {
        return [
            'two_factor_required' => false,
            'token' => $token['token'],
            'token_type' => $token['token_type'],
            'expires_at' => $token['expires_at'],
            'user' => (new UserResource($user->load('roles')))->withPermissions()->resolve(request()),
        ];
    }
}
