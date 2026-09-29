<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\GatewayRegistry;
use App\Domain\Payments\PaymentGatewaySettingsService;
use App\Enums\GatewayMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestPaymentGatewayRequest;
use App\Http\Requests\Settings\UpdatePaymentGatewayRequest;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGatewaySetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PaymentGatewaySettingsController extends Controller
{
    public function __construct(private readonly PaymentGatewaySettingsService $settings) {}

    public function index(): JsonResponse
    {
        $this->settings->ensureRows();

        $gateways = PaymentGatewaySetting::query()
            ->whereIn('gateway', array_keys(GatewayRegistry::definitions()))
            ->orderBy('gateway')
            ->get();

        return ApiResponse::success(PaymentGatewayResource::collection($gateways));
    }

    public function show(string $gateway): JsonResponse
    {
        return ApiResponse::success(new PaymentGatewayResource($this->find($gateway)));
    }

    public function update(UpdatePaymentGatewayRequest $request, string $gateway): JsonResponse
    {
        $setting = $this->settings->update($this->find($gateway), $request->validated(), $request->user());

        return ApiResponse::success(new PaymentGatewayResource($setting), 'Payment gateway settings saved.');
    }

    public function test(TestPaymentGatewayRequest $request, string $gateway): JsonResponse
    {
        $setting = $this->find($gateway);
        $mode = $request->validated('mode') ? GatewayMode::from($request->validated('mode')) : null;

        $result = $this->settings->testConnection($setting, $mode);

        return ApiResponse::success(
            ['result' => $result, 'gateway' => (new PaymentGatewayResource($setting->refresh()))->resolve(request())],
            $result['message']
        );
    }

    private function find(string $gateway): PaymentGatewaySetting
    {
        abort_unless(GatewayRegistry::exists($gateway), 404);

        $this->settings->ensureRows();

        return PaymentGatewaySetting::where('gateway', $gateway)->firstOrFail();
    }
}
