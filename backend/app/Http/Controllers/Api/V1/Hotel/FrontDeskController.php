<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Property\HotelSettings;
use App\Domain\Reservations\AvailabilityService;
use App\Domain\Reservations\StayDates;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receptionist dashboard numbers (spec §57).
 */
class FrontDeskController extends Controller
{
    public function __construct(
        private readonly HotelSettings $settings,
        private readonly AvailabilityService $availability,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($data['date']) ? CarbonImmutable::createFromFormat('!Y-m-d', $data['date']) : $this->settings->today();
        $day = $date->toDateString();

        $arrivals = Reservation::query()
            ->whereDate('check_in', $day)
            ->whereIn('status', [ReservationStatus::PendingPayment->value, ReservationStatus::Confirmed->value, ReservationStatus::CheckedIn->value]);

        $departures = Reservation::query()
            ->whereDate('check_out', $day)
            ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::CheckedIn->value, ReservationStatus::CheckedOut->value]);

        $roomsByStatus = Room::query()
            ->where('property_id', Property::current()->id)
            ->where('is_active', true)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);

        return ApiResponse::success([
            'date' => $day,
            'arrivals' => (clone $arrivals)->count(),
            'arrivals_checked_in' => (clone $arrivals)->where('status', ReservationStatus::CheckedIn->value)->count(),
            'departures' => (clone $departures)->count(),
            'departures_checked_out' => (clone $departures)->where('status', ReservationStatus::CheckedOut->value)->count(),
            'in_house' => Reservation::query()->where('status', ReservationStatus::CheckedIn->value)->count(),
            'pending_payment' => Reservation::query()->where('status', ReservationStatus::PendingPayment->value)->count(),
            'available_tonight' => $this->availability->availableRooms(new StayDates($date, $date->addDay()))->reorder()->count(),
            'total_rooms' => $roomsByStatus->sum(),
            'rooms_by_status' => $roomsByStatus,
        ]);
    }
}
