<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    #[Test]
    public function unknown_routes_return_a_json_404_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'The requested resource was not found.',
                'code' => 'NOT_FOUND',
            ]);
    }

    #[Test]
    public function validation_failures_use_the_standard_format(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    #[Test]
    public function protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    #[Test]
    public function responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    #[Test]
    public function stack_traces_are_not_exposed_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Route::get('api/v1/_boom', fn () => throw new \RuntimeException('secret internals'));

        $this->getJson('/api/v1/_boom')
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'An unexpected error occurred. Please try again.',
                'code' => 'SERVER_ERROR',
            ]);
    }
}
