<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Reservations\ReservationService;
use App\Enums\ReservationSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\PublicReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    /**
     * Online booking. Works for guests without an account; a signed-in
     * customer's booking is linked to their account.
     */
    public function store(PublicReservationRequest $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $customer = $user && $user->isCustomer() && $user->isActive() ? $user : null;

        $result = $this->reservations->create($request->validated(), ReservationSource::Website, $customer);

        return ApiResponse::created([
            'reservation' => (new ReservationResource($result['reservation']))->resolve($request),
            // Lets a guest without an account view (and later pay for) this booking.
            'lookup_token' => $result['lookup_token'],
        ], 'Your rooms are held. Complete payment to confirm the reservation.');
    }

    /**
     * GET /public/reservations/{number}?token=… — guest lookup without an account.
     */
    public function show(Request $request, string $number): JsonResponse
    {
        $token = (string) $request->query('token', '');
        $reservation = strlen($token) === 40 ? $this->reservations->findByLookup($number, $token) : null;

        abort_if($reservation === null, 404);

        return ApiResponse::success(new ReservationResource($reservation));
    }
}
