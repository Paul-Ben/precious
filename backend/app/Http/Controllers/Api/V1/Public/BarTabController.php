<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Payments\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bar\BarTabResource;
use App\Models\BarTab;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The customer's pay link from the bill email (spec §26). */
class BarTabController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function show(Request $request, string $number): JsonResponse
    {
        $tab = $this->find($number, (string) $request->query('token', ''));

        return ApiResponse::success((new BarTabResource($tab->load(['table', 'orders.items', 'payments.receipt'])))->resolve($request));
    }

    public function options(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->payments->tabOptions($this->find($number, (string) $request->query('token', ''))));
    }

    public function pay(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:40'],
            'gateway' => ['nullable', Rule::in(['paystack', 'flutterwave'])],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $payment = $this->payments->initiateForTab($this->find($number, $data['token']), $data['gateway'] ?? null, $data['email'] ?? null);

        return ApiResponse::created(PaymentController::checkout($payment), 'Redirecting you to the secure payment page.');
    }

    private function find(string $number, string $token): BarTab
    {
        $tab = BarTab::query()->where('number', $number)->first();
        abort_if(! $tab || ! $tab->tokenMatches($token), 404);

        return $tab;
    }
}
