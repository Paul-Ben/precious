<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\AvailabilityService;
use App\Domain\Reservations\PricingService;
use App\Domain\Reservations\ReservationService;
use App\Domain\Reservations\StayDates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AvailabilityRequest;
use App\Http\Requests\Hotel\QuoteRequest;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated catalogue: hotel details, room types, availability
 * and price quotes. Prices always come from the server (spec §75).
 */
class CatalogController extends Controller
{
    /** Policies that are safe and useful to show guests. */
    private const PUBLIC_POLICIES = [
        'deposit_percent', 'hold_minutes', 'check_in_time', 'check_out_time',
        'free_cancellation_hours', 'max_nights', 'max_rooms_per_booking',
        'booking_window_days', 'vat_percent', 'vat_on_accommodation',
        'pass_gateway_fees_to_customer',
    ];

    public function __construct(
        private readonly HotelSettings $settings,
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly ReservationService $reservations,
    ) {}

    public function property(): JsonResponse
    {
        return ApiResponse::success(
            (new PropertyResource(Property::current()))
                ->withPolicies(array_intersect_key($this->settings->all(), array_flip(self::PUBLIC_POLICIES)))
        );
    }

    public function roomTypes(): JsonResponse
    {
        $types = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->active()
            ->with(['amenities', 'images'])
            ->orderBy('sort_order')
            ->orderBy('base_rate')
            ->get();

        return ApiResponse::success(RoomTypeResource::collection($types));
    }

    public function roomType(string $slug): JsonResponse
    {
        $type = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->where('slug', $slug)
            ->active()
            ->with(['amenities', 'images'])
            ->firstOrFail();

        return ApiResponse::success(new RoomTypeResource($type));
    }

    /**
     * Room types with their availability and a price for one room of each.
     */
    public function availability(AvailabilityRequest $request): JsonResponse
    {
        $this->reservations->expireStaleHolds();

        $dates = StayDates::fromStrings($request->validated('check_in'), $request->validated('check_out'));
        $adults = (int) $request->validated('adults', 1);
        $children = (int) $request->validated('children', 0);
        $counts = $this->availability->countsByType($dates);

        $types = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->active()
            ->with(['amenities', 'images'])
            ->orderBy('sort_order')
            ->orderBy('base_rate')
            ->get();

        $results = $types->map(function (RoomType $type) use ($counts, $dates, $adults, $children) {
            $quote = $this->pricing->quote([['room_type' => $type, 'quantity' => 1]], $dates);
            $fitsParty = $adults <= $type->max_adults && $adults + $children <= $type->max_occupancy;

            return [
                ...(new RoomTypeResource($type))->resolve(request()),
                'available_count' => $counts[$type->id] ?? 0,
                'fits_party_in_one_room' => $fitsParty,
                'quote' => $quote,
            ];
        })->values();

        return ApiResponse::success([
            'check_in' => $dates->checkInDate(),
            'check_out' => $dates->checkOutDate(),
            'nights' => $dates->nights(),
            'adults' => $adults,
            'children' => $children,
            'room_types' => $results,
        ]);
    }

    /**
     * Exact price for a basket of rooms (the booking summary).
     */
    public function quote(QuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $prepared = $this->reservations->prepare(
            $data['check_in'], $data['check_out'], $data['rooms'], (int) $data['adults'], (int) ($data['children'] ?? 0)
        );

        $counts = $this->availability->countsByType($prepared['dates']);
        $shortages = collect($prepared['lines'])
            ->filter(fn ($l) => ($counts[$l['room_type']->id] ?? 0) < $l['quantity'])
            ->map(fn ($l) => ['room_type_id' => $l['room_type']->id, 'available' => $counts[$l['room_type']->id] ?? 0])
            ->values();

        return ApiResponse::success([
            ...$prepared['quote'],
            'available' => $shortages->isEmpty(),
            'shortages' => $shortages,
        ]);
    }
}
