<?php

namespace Database\Seeders;

use App\Domain\Payments\PaymentGatewaySettingsService;
use Illuminate\Database\Seeder;

/**
 * Creates disabled Paystack/Flutterwave rows. Keys are entered by an admin in
 * Staff > Settings > Payment gateways.
 */
class PaymentGatewaySeeder extends Seeder
{
    public function run(PaymentGatewaySettingsService $service): void
    {
        $service->ensureRows();
    }
}
