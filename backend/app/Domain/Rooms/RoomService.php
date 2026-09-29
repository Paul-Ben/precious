<?php

namespace App\Domain\Rooms;

use App\Domain\Audit\AuditService;
use App\Enums\RoomBlockReason;
use App\Enums\RoomStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RoomService
{
    private const AUDITED = ['room_type_id', 'number', 'floor', 'status', 'notes', 'maintenance_notes', 'is_active'];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Room
    {
        return DB::transaction(function () use ($data) {
            $room = Room::create([
                ...$data,
                'property_id' => Property::current()->id,
                'status' => $data['status'] ?? RoomStatus::Available->value,
            ]);

            $this->audit->record('rooms.created', $room, null, $this->snapshot($room));

            return $room->load('roomType');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Room $room, array $data): Room
    {
        return DB::transaction(function () use ($room, $data) {
            $before = $this->snapshot($room);
            $room->fill($data)->save();
            [$old, $new] = $this->audit->diff($before, $this->snapshot($room));

            if ($new !== []) {
                $this->audit->record('rooms.updated', $room, $old, $new);
            }

            return $room->load('roomType');
        });
    }

    public function changeStatus(Room $room, RoomStatus $status, ?string $notes): Room
    {
        return DB::transaction(function () use ($room, $status, $notes) {
            $old = $room->status;
            $room->status = $status;

            if ($status === RoomStatus::Maintenance && $notes !== null) {
                $room->maintenance_notes = $notes;
            }

            $room->save();

            $this->audit->record('rooms.status_changed', $room,
                ['status' => $old?->value],
                ['status' => $status->value],
                array_filter(['notes' => $notes])
            );

            return $room->load('roomType');
        });
    }

    public function delete(Room $room): void
    {
        if ($room->reservationRooms()->exists()) {
            throw new BusinessRuleException(
                'This room has booking history and cannot be deleted. Deactivate it instead.',
                'ROOM_IN_USE'
            );
        }

        DB::transaction(function () use ($room) {
            $snapshot = $this->snapshot($room);
            $room->delete();
            $this->audit->record('rooms.deleted', $room, $snapshot, null);
        });
    }

    public function block(Room $room, CarbonImmutable $from, CarbonImmutable $until, RoomBlockReason $reason, ?string $notes, User $actor): RoomBlock
    {
        if ($until->lte($from)) {
            throw new BusinessRuleException('The block must end after it starts.', 'INVALID_DATES');
        }

        return DB::transaction(function () use ($room, $from, $until, $reason, $notes, $actor) {
            Room::query()->whereKey($room->id)->lockForUpdate()->first();

            $conflicts = $room->reservationRooms()
                ->where('is_active', true)
                ->where('check_in', '<', $until->toDateString())
                ->where('check_out', '>', $from->toDateString())
                ->with('reservation:id,number')
                ->get();

            if ($conflicts->isNotEmpty()) {
                throw new BusinessRuleException(
                    'The room is booked during these dates. Move the reservation(s) first.',
                    'ROOM_HAS_BOOKINGS',
                    409,
                    ['reservations' => $conflicts->pluck('reservation.number')->unique()->values()->all()]
                );
            }

            $block = $room->blocks()->create([
                'starts_on' => $from->toDateString(),
                'ends_on' => $until->toDateString(),
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('rooms.blocked', $room, null, [
                'block_id' => $block->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => $until->toDateString(),
                'reason' => $reason->value,
            ]);

            return $block;
        });
    }

    public function unblock(Room $room, RoomBlock $block): void
    {
        abort_unless($block->room_id === $room->id, 404);

        DB::transaction(function () use ($room, $block) {
            $block->delete();
            $this->audit->record('rooms.unblocked', $room, [
                'block_id' => $block->id,
                'starts_on' => $block->starts_on->toDateString(),
                'ends_on' => $block->ends_on->toDateString(),
            ], null);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Room $room): array
    {
        $data = $room->only(self::AUDITED);
        $data['status'] = $room->status instanceof RoomStatus ? $room->status->value : $room->status;

        return $data;
    }
}
