<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Reservations\AvailabilityService;
use App\Domain\Reservations\PricingService;
use App\Domain\Reservations\ReservationService;
use App\Domain\Reservations\StayDates;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AvailabilityRequest;
use App\Http\Requests\Hotel\CancelReservationRequest;
use App\Http\Requests\Hotel\StaffReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', Rule::enum(ReservationSource::class)],
            'arrivals_on' => ['nullable', 'date_format:Y-m-d'],
            'departures_on' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statuses = collect(explode(',', (string) ($filters['status'] ?? '')))
            ->map(fn ($s) => strtoupper(trim($s)))
            ->filter(fn ($s) => ReservationStatus::tryFrom($s) !== null)
            ->values();

        $list = Reservation::query()
            ->with(['guest', 'rooms.room', 'rooms.roomType'])
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $digits = preg_replace('/\D+/', '', $search);

                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(number) LIKE ?', [$term])
                    ->orWhereHas('guest', fn (Builder $g) => $g
                        ->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                        ->when(strlen($digits) >= 4, fn ($p) => $p->orWhere('phone', 'like', '%'.$digits.'%')))
                    ->orWhereHas('rooms.room', fn (Builder $r) => $r->where('number', trim($search))));
            })
            ->when($statuses->isNotEmpty(), fn ($q) => $q->whereIn('status', $statuses))
            ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->when($filters['arrivals_on'] ?? null, fn ($q, $d) => $q->whereDate('check_in', $d))
            ->when($filters['departures_on'] ?? null, fn ($q, $d) => $q->whereDate('check_out', $d))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('check_out', '>', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('check_in', '<', $d))
            ->orderBy('check_in')
            ->orderBy('number')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $resource = ReservationResource::collection($list);
        $resource->collection->each->forStaff();

        return ApiResponse::success($resource);
    }

    /**
     * Availability for the booking desk: counts and room numbers per type.
     */
    public function availability(AvailabilityRequest $request): JsonResponse
    {
        $this->reservations->expireStaleHolds();
        $dates = StayDates::fromStrings($request->validated('check_in'), $request->validated('check_out'));

        $rooms = $this->availability->availableRooms($dates)->get(['rooms.id', 'rooms.number', 'rooms.room_type_id'])->groupBy('room_type_id');

        $types = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('base_rate')
            ->get();

        return ApiResponse::success([
            'check_in' => $dates->checkInDate(),
            'check_out' => $dates->checkOutDate(),
            'nights' => $dates->nights(),
            'room_types' => $types->map(fn (RoomType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'base_rate' => $type->base_rate,
                'max_adults' => $type->max_adults,
                'max_occupancy' => $type->max_occupancy,
                'available_count' => ($rooms[$type->id] ?? collect())->count(),
                'available_rooms' => ($rooms[$type->id] ?? collect())->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number])->values(),
                'quote' => $this->pricing->quote([['room_type' => $type, 'quantity' => 1]], $dates),
            ])->values(),
        ]);
    }

    public function store(StaffReservationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->reservations->create($data, ReservationSource::from($data['source']), $request->user());

        return ApiResponse::created(
            (new ReservationResource($result['reservation']))->forStaff(),
            'Reservation created and rooms held until '.$result['reservation']->expires_at?->timezone(Property::current()->timezone)->format('D j M, H:i').'.'
        );
    }

    public function show(Reservation $reservation): JsonResponse
    {
        return ApiResponse::success((new ReservationResource($this->reservations->load($reservation)))->forStaff());
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate([
            'special_requests' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success((new ReservationResource($this->reservations->updateNotes($reservation, $data)))->forStaff(), 'Reservation updated.');
    }

    public function cancel(CancelReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $cancelled = $this->reservations->cancel($reservation, $request->validated('reason'), $request->user());

        return ApiResponse::success((new ReservationResource($cancelled))->forStaff(), 'Reservation cancelled and rooms released.');
    }
}
