<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GatewayFeeSettingsTest extends TestCase
{
    #[Test]
    public function published_fees_are_the_default_and_admins_can_override_them(): void
    {
        $this->actingAsUser($this->staff('Administrator'));

        $this->getJson('/api/v1/settings/payment-gateways/paystack')
            ->assertOk()
            ->assertJsonPath('data.fees.percent', '1.5')
            ->assertJsonPath('data.fees.cap', '2000.00');

        $this->patchJson('/api/v1/settings/payment-gateways/paystack', [
            'fees' => ['percent' => '1.2', 'flat' => '50', 'flat_waived_below' => null, 'cap' => '1500'],
        ])->assertOk()
            ->assertJsonPath('data.fees.percent', '1.2')
            ->assertJsonPath('data.fees.flat', '50.00')
            ->assertJsonPath('data.fees.flat_waived_below', null)
            ->assertJsonPath('data.fees.cap', '1500.00')
            ->assertJsonPath('data.fee_defaults.percent', '1.5');

        $this->assertTrue(AuditLog::where('action', 'settings.payment_gateway_updated')->exists());

        // null restores the published schedule.
        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['fees' => null])
            ->assertOk()->assertJsonPath('data.fees.percent', '1.5');

        $this->patchJson('/api/v1/settings/payment-gateways/paystack', ['fees' => ['percent' => '150']])
            ->assertStatus(422)->assertJsonValidationErrors('fees.percent');
    }
}
