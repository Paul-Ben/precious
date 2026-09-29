<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Obi',
            'email' => 'Ada@Example.com',
            'phone' => '+2348012345678',
            'password' => 'Str0ng-Password!',
            'password_confirmation' => 'Str0ng-Password!',
        ], $overrides);
    }

    #[Test]
    public function a_customer_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.type', 'customer')
            ->assertJsonPath('data.user.roles', ['Customer'])
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->assertNotEmpty($response->json('data.token'));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Customer'));
        $this->assertTrue(AuditLog::where('action', 'auth.registered')->where('auditable_id', $user->id)->exists());

        $this->withBearer($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    #[Test]
    public function weak_passwords_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'password',
            'password_confirmation' => 'password',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function email_addresses_are_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'ADA@EXAMPLE.COM']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    #[Test]
    public function registration_cannot_create_staff_or_assign_roles(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'type' => 'staff',
            'roles' => ['Super Administrator'],
        ]))->assertCreated()
            ->assertJsonPath('data.user.type', 'customer')
            ->assertJsonPath('data.user.roles', ['Customer']);
    }
}
