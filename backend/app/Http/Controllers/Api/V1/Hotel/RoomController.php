<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Property\HotelSettings;
use App\Domain\Rooms\RoomService;
use App\Enums\ReservationStatus;
use App\Enums\RoomBlockReason;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\RoomBlockRequest;
use App\Http\Requests\Hotel\RoomRequest;
use App\Http\Requests\Hotel\RoomStatusRequest;
use App\Http\Resources\RoomBlockResource;
use App\Http\Resources\RoomResource;
use App\Models\Property;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function __construct(
        private readonly RoomService $rooms,
        private readonly HotelSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'room_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(RoomStatus::class)],
            'search' => ['nullable', 'string', 'max:20'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $rooms = Room::query()
            ->where('property_id', Property::current()->id)
            ->with('roomType')
            ->when($filters['room_type_id'] ?? null, fn ($q, $id) => $q->where('room_type_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('number', 'like', $s.'%'))
            ->when(! ($filters['include_inactive'] ?? false), fn ($q) => $q->where('is_active', true))
            ->orderByRaw('LENGTH(number), number')
            ->get();

        return ApiResponse::success(RoomResource::collection($rooms));
    }

    /**
     * Room board: every room with its status and who is in / arriving today.
     */
    public function board(): JsonResponse
    {
        $today = $this->settings->today()->toDateString();

        $lines = ReservationRoom::query()
            ->where('is_active', true)
            ->where('check_in', '<=', $today)
            ->where('check_out', '>=', $today)
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', [
                ReservationStatus::PendingPayment->value,
                ReservationStatus::Confirmed->value,
                ReservationStatus::CheckedIn->value,
            ]))
            ->with('reservation.guest')
            ->get()
            ->groupBy('room_id');

        $rooms = Room::query()
            ->where('property_id', Property::current()->id)
            ->where('is_active', true)
            ->with(['roomType', 'blocks' => fn ($q) => $q->where('ends_on', '>', $today)->orderBy('starts_on')])
            ->orderByRaw('LENGTH(number), number')
            ->get();

        return ApiResponse::success($rooms->map(function (Room $room) use ($lines, $today) {
            $stays = ($lines[$room->id] ?? collect())->map(fn (ReservationRoom $l) => [
                'reservation_id' => $l->reservation_id,
                'number' => $l->reservation->number,
                'status' => $l->reservation->status->value,
                'guest' => $l->reservation->guest?->fullName(),
                'check_in' => $l->check_in->toDateString(),
                'check_out' => $l->check_out->toDateString(),
                'arriving_today' => $l->check_in->toDateString() === $today,
                'departing_today' => $l->check_out->toDateString() === $today,
            ])->values();

            return [
                ...(new RoomResource($room))->resolve(request()),
                'today' => $stays,
            ];
        })->values(), meta: ['date' => $today]);
    }

    public function store(RoomRequest $request): JsonResponse
    {
        return ApiResponse::created(new RoomResource($this->rooms->create($request->validated())), 'Room created.');
    }

    public function show(Room $room): JsonResponse
    {
        return ApiResponse::success(new RoomResource($room->load([
            'roomType',
            'blocks' => fn ($q) => $q->with('creator')->orderByDesc('starts_on')->limit(50),
        ])));
    }

    public function update(RoomRequest $request, Room $room): JsonResponse
    {
        return ApiResponse::success(new RoomResource($this->rooms->update($room, $request->validated())), 'Room updated.');
    }

    public function destroy(Room $room): JsonResponse
    {
        $this->rooms->delete($room);

        return ApiResponse::success(null, 'Room deleted.');
    }

    public function updateStatus(RoomStatusRequest $request, Room $room): JsonResponse
    {
        $updated = $this->rooms->changeStatus($room, RoomStatus::from($request->validated('status')), $request->validated('notes'));

        return ApiResponse::success(new RoomResource($updated), 'Room status updated.');
    }

    public function storeBlock(RoomBlockRequest $request, Room $room): JsonResponse
    {
        $data = $request->validated();
        $block = $this->rooms->block(
            $room,
            CarbonImmutable::createFromFormat('!Y-m-d', $data['starts_on']),
            CarbonImmutable::createFromFormat('!Y-m-d', $data['ends_on']),
            RoomBlockReason::from($data['reason']),
            $data['notes'] ?? null,
            $request->user(),
        );

        return ApiResponse::created(new RoomBlockResource($block->load('creator')), 'Room blocked for those dates.');
    }

    public function destroyBlock(Room $room, RoomBlock $block): JsonResponse
    {
        $this->rooms->unblock($room, $block);

        return ApiResponse::success(null, 'Block removed.');
    }
}
