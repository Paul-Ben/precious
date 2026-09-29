<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Payments\PaymentService;
use App\Domain\Reservations\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StartPaymentRequest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online payment for guests without an account (reservation number + the
 * lookup token they received when booking).
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly ReservationService $reservations,
    ) {}

    public function options(Request $request, string $number): JsonResponse
    {
        return ApiResponse::success($this->payments->options($this->find($number, (string) $request->query('token', ''))));
    }

    public function store(StartPaymentRequest $request, string $number): JsonResponse
    {
        $reservation = $this->find($number, $request->validated('token'));
        $user = $request->user('sanctum');

        $payment = $this->payments->initiate(
            $reservation,
            $request->validated('option'),
            $request->validated('gateway'),
            $user && $user->isCustomer() ? $user : null,
        );

        return ApiResponse::created(self::checkout($payment), 'Redirecting you to the secure payment page.');
    }

    /**
     * Called by the /pay/callback page after the gateway redirects back.
     * Only the unguessable reference is needed; nothing personal is returned.
     */
    public function verify(Request $request): JsonResponse
    {
        $reference = (string) $request->validate(['reference' => ['required', 'string', 'max:64']])['reference'];

        $payment = Payment::query()->where('reference', $reference)->first();
        abort_if($payment === null, 404);

        $payment = $this->payments->verify($payment)->load('receipt');
        $reservation = $payment->payable instanceof Reservation ? $payment->payable->refresh() : null;

        return ApiResponse::success([
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'customer_fee' => $payment->customer_fee,
            'charged_amount' => $payment->charged_amount,
            'failure_reason' => $payment->status->value === 'FAILED' ? $payment->failure_reason : null,
            'receipt_number' => $payment->receipt?->number,
            'reservation' => $reservation ? [
                'number' => $reservation->number,
                'status' => $reservation->status->value,
                'payment_status' => $reservation->payment_status->value,
                'balance' => Money::toDecimal(max(0, $reservation->balanceMinor())),
            ] : null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function checkout(Payment $payment): array
    {
        return [
            'reference' => $payment->reference,
            'gateway' => $payment->gateway,
            'amount' => $payment->amount,
            'customer_fee' => $payment->customer_fee,
            'charged_amount' => $payment->charged_amount,
            'authorization_url' => $payment->authorization_url,
        ];
    }

    private function find(string $number, string $token): Reservation
    {
        $reservation = strlen($token) === 40 ? $this->reservations->findByLookup($number, $token) : null;
        abort_if($reservation === null, 404);

        return $reservation;
    }
}
