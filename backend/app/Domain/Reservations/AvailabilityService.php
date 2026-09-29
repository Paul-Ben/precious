<?php

namespace App\Domain\Reservations;

use App\Enums\RoomStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Spec §13: Available rooms = active rooms of the type
 *   − rooms with an overlapping active reservation line (pending holds,
 *     confirmed and in-house stays)
 *   − rooms with an overlapping block (maintenance / out of service / blocked)
 *   − rooms marked OUT_OF_SERVICE.
 *
 * The query is advisory; the database exclusion constraint on
 * reservation_rooms is what finally guarantees no double booking.
 */
class AvailabilityService
{
    /**
     * Rooms free for the whole stay, lowest room number first.
     */
    public function availableRooms(StayDates $dates, ?int $roomTypeId = null, ?Property $property = null): Builder
    {
        $property ??= Property::current();
        $in = $dates->checkInDate();
        $out = $dates->checkOutDate();

        return Room::query()
            ->where('rooms.property_id', $property->id)
            ->where('rooms.is_active', true)
            ->where('rooms.status', '!=', RoomStatus::OutOfService->value)
            ->when($roomTypeId, fn (Builder $q) => $q->where('rooms.room_type_id', $roomTypeId))
            ->whereNotExists(fn (QueryBuilder $q) => $q
                ->selectRaw('1')
                ->from('reservation_rooms')
                ->whereColumn('reservation_rooms.room_id', 'rooms.id')
                ->where('reservation_rooms.is_active', true)
                ->where('reservation_rooms.check_in', '<', $out)
                ->where('reservation_rooms.check_out', '>', $in))
            ->whereNotExists(fn (QueryBuilder $q) => $q
                ->selectRaw('1')
                ->from('room_blocks')
                ->whereColumn('room_blocks.room_id', 'rooms.id')
                ->where('room_blocks.starts_on', '<', $out)
                ->where('room_blocks.ends_on', '>', $in))
            ->orderByRaw('LENGTH(rooms.number), rooms.number');
    }

    /**
     * Available room count per active room type id.
     *
     * @return array<int, int>
     */
    public function countsByType(StayDates $dates, ?Property $property = null): array
    {
        return $this->availableRooms($dates, null, $property)
            ->reorder()
            ->selectRaw('rooms.room_type_id, COUNT(*) AS available')
            ->groupBy('rooms.room_type_id')
            ->pluck('available', 'room_type_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function countFor(StayDates $dates, RoomType $type): int
    {
        return $this->availableRooms($dates, $type->id)->reorder()->count();
    }
}
