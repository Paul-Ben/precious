<?php

namespace App\Domain\Payments;

use App\Enums\GatewayMode;

/**
 * Describes the payment gateways the platform supports and which credentials
 * an administrator must supply for each mode.
 *
 * The frontend renders its configuration form from this definition (via the
 * API), so adding a gateway later is a backend-only change.
 */
final class GatewayRegistry
{
    public const PAYSTACK = 'paystack';

    public const FLUTTERWAVE = 'flutterwave';

    /**
     * @return array<string, array{
     *     name: string,
     *     docs_url: string,
     *     dashboard_url: string,
     *     test_url: string,
     *     fields: array<string, array{label: string, secret: bool, required: bool, help: string, prefix: array{test: ?string, live: ?string}}>
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::PAYSTACK => [
                'name' => 'Paystack',
                'docs_url' => 'https://paystack.com/docs/api/',
                'dashboard_url' => 'https://dashboard.paystack.com/#/settings/developers',
                // A cheap authenticated read used to validate the secret key.
                'test_url' => 'https://api.paystack.co/transaction?perPage=1',
                'fields' => [
                    'public_key' => [
                        'label' => 'Public key',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Starts with pk_test_ or pk_live_.',
                        'prefix' => ['test' => 'pk_test_', 'live' => 'pk_live_'],
                    ],
                    'secret_key' => [
                        'label' => 'Secret key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Starts with sk_test_ or sk_live_. Also used to verify webhook signatures.',
                        'prefix' => ['test' => 'sk_test_', 'live' => 'sk_live_'],
                    ],
                ],
            ],
            self::FLUTTERWAVE => [
                'name' => 'Flutterwave',
                'docs_url' => 'https://developer.flutterwave.com/docs',
                'dashboard_url' => 'https://app.flutterwave.com/dashboard/settings/apis',
                'test_url' => 'https://api.flutterwave.com/v3/transactions?page=1',
                'fields' => [
                    'public_key' => [
                        'label' => 'Public key',
                        'secret' => false,
                        'required' => true,
                        'help' => 'Starts with FLWPUBK_TEST- or FLWPUBK-.',
                        'prefix' => ['test' => 'FLWPUBK_TEST-', 'live' => 'FLWPUBK-'],
                    ],
                    'secret_key' => [
                        'label' => 'Secret key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Starts with FLWSECK_TEST- or FLWSECK-.',
                        'prefix' => ['test' => 'FLWSECK_TEST-', 'live' => 'FLWSECK-'],
                    ],
                    'encryption_key' => [
                        'label' => 'Encryption key',
                        'secret' => true,
                        'required' => true,
                        'help' => 'Shown on the same Flutterwave API settings page.',
                        'prefix' => ['test' => null, 'live' => null],
                    ],
                    'webhook_secret_hash' => [
                        'label' => 'Webhook secret hash',
                        'secret' => true,
                        'required' => true,
                        'help' => 'The "Secret hash" you set under Settings > Webhooks. Flutterwave sends it in the verif-hash header.',
                        'prefix' => ['test' => null, 'live' => null],
                    ],
                ],
            ],
        ];
    }

    public static function exists(string $gateway): bool
    {
        return array_key_exists($gateway, self::definitions());
    }

    public static function get(string $gateway): array
    {
        return self::definitions()[$gateway];
    }

    /**
     * Credential storage key, e.g. "live_secret_key".
     */
    public static function credentialKey(GatewayMode $mode, string $field): string
    {
        return $mode->value.'_'.$field;
    }
}
