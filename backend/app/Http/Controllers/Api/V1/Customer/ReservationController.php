<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Domain\Payments\PaymentService;
use App\Domain\Reservations\ReservationService;
use App\Http\Controllers\Api\V1\Public\PaymentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\CancelReservationRequest;
use App\Http\Requests\Payments\StartPaymentRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A signed-in customer's own reservations. Ownership is enforced by the
 * query scope, so other people's reservations return 404.
 */
class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $list = Reservation::query()
            ->ownedBy($request->user())
            ->with(['guest', 'rooms.roomType'])
            ->orderByDesc('check_in')
            ->paginate(10);

        return ApiResponse::success(ReservationResource::collection($list));
    }

    public function show(Request $request, string $reservation): JsonResponse
    {
        return ApiResponse::success(new ReservationResource($this->find($request, $reservation)));
    }

    public function cancel(CancelReservationRequest $request, string $reservation): JsonResponse
    {
        $cancelled = $this->reservations->cancel($this->find($request, $reservation), $request->validated('reason'), $request->user());

        return ApiResponse::success(new ReservationResource($cancelled), 'Your reservation has been cancelled.');
    }

    public function paymentOptions(Request $request, string $reservation): JsonResponse
    {
        return ApiResponse::success($this->payments->options($this->find($request, $reservation)));
    }

    public function pay(StartPaymentRequest $request, string $reservation): JsonResponse
    {
        $payment = $this->payments->initiate(
            $this->find($request, $reservation),
            $request->validated('option'),
            $request->validated('gateway'),
            $request->user(),
        );

        return ApiResponse::created(PaymentController::checkout($payment), 'Redirecting you to the secure payment page.');
    }

    private function find(Request $request, string $id): Reservation
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->reservations->load(
            Reservation::query()->ownedBy($request->user())->findOrFail($id)
        );
    }
}
