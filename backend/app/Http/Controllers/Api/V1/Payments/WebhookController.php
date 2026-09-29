<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/payments/{gateway}
 *
 * Signature checked against the stored secrets; the payment is then
 * re-verified with the gateway's API before any money is credited.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(Request $request, string $gateway): JsonResponse
    {
        abort_unless(GatewayRegistry::exists($gateway), 404);

        $headers = collect($request->headers->all())
            ->mapWithKeys(fn (array $values, string $name) => [strtolower($name) => $values[0] ?? null])
            ->all();

        $outcome = $this->payments->handleWebhook($gateway, $request->getContent(), $headers);

        if ($outcome === 'invalid_signature') {
            return ApiResponse::error('Invalid signature.', 401, 'INVALID_SIGNATURE');
        }

        // Always 200 for authentic deliveries so the gateway stops retrying.
        return ApiResponse::success(['outcome' => $outcome], 'Received.');
    }
}
