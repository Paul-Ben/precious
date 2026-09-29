<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Reservations\ReservationService;
use App\Domain\Stays\StayService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stays\CheckInRequest;
use App\Http\Requests\Stays\CheckOutRequest;
use App\Http\Requests\Stays\ExtendStayRequest;
use App\Http\Requests\Stays\MoveStayRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\StayResource;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Stay;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StayController extends Controller
{
    public function __construct(
        private readonly StayService $stays,
        private readonly ReservationService $reservations,
    ) {}

    /** In-house guests (open stays). */
    public function index(Request $request): JsonResponse
    {
        $open = Stay::query()
            ->where('property_id', Property::current()->id)
            ->open()
            ->with(['room', 'guest', 'reservation'])
            ->get()
            ->sortBy(fn (Stay $s) => [strlen((string) $s->room?->number), $s->room?->number])
            ->values();

        return ApiResponse::success(StayResource::collection($open));
    }

    public function checkInOptions(string $reservation): JsonResponse
    {
        return ApiResponse::success($this->stays->checkInOptions($this->find($reservation)));
    }

    public function checkIn(CheckInRequest $request, string $reservation): JsonResponse
    {
        $model = $this->stays->checkIn($this->find($reservation), $request->validated(), $request->user());

        return $this->respond($model, 'Guest checked in.');
    }

    public function extend(ExtendStayRequest $request, string $reservation): JsonResponse
    {
        $model = $this->stays->extend($this->find($reservation), $request->validated('check_out'), $request->user());

        return $this->respond($model, 'Stay extended. Extra nights were added to the bill.');
    }

    public function move(MoveStayRequest $request, string $stay): JsonResponse
    {
        $model = Stay::query()->where('property_id', Property::current()->id)->findOrFail($stay);
        $new = $this->stays->move($model, (int) $request->validated('room_id'), $request->validated('reason'), $request->user());

        return $this->respond($new->reservation, 'Guest moved to room '.$new->room?->number.'.');
    }

    public function checkOutPreview(string $reservation): JsonResponse
    {
        $model = $this->find($reservation);

        return ApiResponse::success([
            ...$this->stays->checkOutPreview($model),
            'can_override_balance' => request()->user()->can('checkouts.override_balance'),
        ]);
    }

    public function checkOut(CheckOutRequest $request, string $reservation): JsonResponse
    {
        $result = $this->stays->checkOut(
            $this->find($reservation),
            $request->validated(),
            $request->user(),
            $request->user()->can('checkouts.override_balance'),
        );

        return ApiResponse::success([
            'reservation' => (new ReservationResource($this->reservations->load($result['reservation'])))->forStaff()->resolve($request),
            'statement_number' => $result['statement']->number,
        ], 'Guest checked out. Final bill '.$result['statement']->number.' issued.');
    }

    private function find(string $id): Reservation
    {
        return Reservation::query()->where('property_id', Property::current()->id)->findOrFail($id);
    }

    private function respond(Reservation $reservation, string $message): JsonResponse
    {
        return ApiResponse::success((new ReservationResource($this->reservations->load($reservation->refresh())))->forStaff(), $message);
    }
}
