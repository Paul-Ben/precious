<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\PaymentGatewaySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    private const PAYSTACK_TEST = [
        'public_key' => 'pk_test_1111111111111111',
        'secret_key' => 'sk_test_2222222222222222abcd',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser($this->staff('Administrator'));
    }

    #[Test]
    public function both_gateways_are_listed_disabled_by_default(): void
    {
        $this->getJson('/api/v1/settings/payment-gateways')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.gateway', 'flutterwave')
            ->assertJsonPath('data.1.gateway', 'paystack')
            ->assertJsonPath('data.1.is_enabled', false)
            ->assertJsonPath('data.1.mode', 'test');
    }

    #[Test]
    public function keys_are_encrypted_at_rest_and_masked_in_responses(): void
    {
        $response = $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['test' => self::PAYSTACK_TEST],
        ])->assertOk()
            ->assertJsonPath('data.credentials.test.public_key.preview', self::PAYSTACK_TEST['public_key'])
            ->assertJsonPath('data.credentials.test.secret_key.set', true)
            ->assertJsonPath('data.credentials.test.secret_key.preview', '••••abcd')
            ->assertJsonPath('data.credentials.live.secret_key.set', false);

        $this->assertStringNotContainsString(self::PAYSTACK_TEST['secret_key'], $response->getContent());

        $raw = DB::table('payment_gateway_settings')->where('gateway', 'paystack')->value('credentials');
        $this->assertStringNotContainsString('sk_test_', $raw);

        $this->assertSame(
            self::PAYSTACK_TEST['secret_key'],
            PaymentGatewaySetting::where('gateway', 'paystack')->first()->credential('secret_key')
        );
    }

    #[Test]
    public function blank_secret_fields_keep_the_saved_value(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['credentials' => ['test' => self::PAYSTACK_TEST]]);

        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['test' => ['public_key' => 'pk_test_9999', 'secret_key' => '']],
        ])->assertOk();

        $setting = PaymentGatewaySetting::where('gateway', 'paystack')->first();
        $this->assertSame('pk_test_9999', $setting->credential('public_key'));
        $this->assertSame(self::PAYSTACK_TEST['secret_key'], $setting->credential('secret_key'));
    }

    #[Test]
    public function keys_for_the_wrong_mode_are_rejected(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['live' => ['secret_key' => 'sk_test_abc']],
        ])->assertStatus(422)->assertJsonValidationErrors('credentials.live.secret_key');

        $this->patchJson('/api/v1/settings/payment-gateways/flutterwave', [
            'credentials' => ['live' => ['public_key' => 'FLWPUBK_TEST-abc']],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_gateway_cannot_be_enabled_without_complete_credentials_for_its_mode(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['is_enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GATEWAY_INCOMPLETE');

        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['test' => self::PAYSTACK_TEST],
            'is_enabled' => true,
            'is_default' => true,
        ])->assertOk()->assertJsonPath('data.is_enabled', true)->assertJsonPath('data.is_default', true);

        // Switching to live without live keys is refused while enabled.
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['mode' => 'live'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GATEWAY_INCOMPLETE');
    }

    #[Test]
    public function only_one_gateway_can_be_default(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['test' => self::PAYSTACK_TEST], 'is_enabled' => true, 'is_default' => true,
        ])->assertOk();

        $this->patchJson('/api/v1/settings/payment-gateways/flutterwave', [
            'credentials' => ['test' => [
                'public_key' => 'FLWPUBK_TEST-1', 'secret_key' => 'FLWSECK_TEST-2',
                'encryption_key' => 'enc', 'webhook_secret_hash' => 'hash',
            ]],
            'is_enabled' => true,
            'is_default' => true,
        ])->assertOk();

        $this->assertSame(['flutterwave'], PaymentGatewaySetting::where('is_default', true)->pluck('gateway')->all());
    }

    #[Test]
    public function changes_are_audited_without_secret_values(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'credentials' => ['test' => self::PAYSTACK_TEST],
        ])->assertOk();

        $log = AuditLog::where('action', 'settings.payment_gateway_updated')->latest('created_at')->firstOrFail();

        $this->assertStringNotContainsString('sk_test_', json_encode($log->toArray()));
        $this->assertNotNull($log->metadata);
    }

    #[Test]
    public function the_connection_test_uses_the_saved_secret_key(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['credentials' => ['test' => self::PAYSTACK_TEST]]);

        Http::fake(['api.paystack.co/*' => Http::response(['status' => true], 200)]);

        $this->postJson('/api/v1/settings/payment-gateways/paystack/test')
            ->assertOk()
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.gateway.last_test.succeeded', true);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::PAYSTACK_TEST['secret_key']));
    }

    #[Test]
    public function a_rejected_key_is_reported(): void
    {
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['credentials' => ['test' => self::PAYSTACK_TEST]]);

        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 401)]);

        $this->postJson('/api/v1/settings/payment-gateways/paystack/test')
            ->assertOk()
            ->assertJsonPath('data.result.success', false)
            ->assertJsonPath('data.result.message', 'The gateway rejected the secret key.');
    }

    #[Test]
    public function unknown_gateways_return_404(): void
    {
        $this->getJson('/api/v1/settings/payment-gateways/stripe')->assertNotFound();
    }

    #[Test]
    public function managers_without_the_permission_are_forbidden(): void
    {
        $this->actingAsUser($this->staff('Hotel Manager'))
            ->getJson('/api/v1/settings/payment-gateways')
            ->assertForbidden();
    }
}
