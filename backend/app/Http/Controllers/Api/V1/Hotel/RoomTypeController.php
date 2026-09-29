<?php

namespace App\Http\Controllers\Api\V1\Hotel;

use App\Domain\Rooms\RoomTypeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\RoomTypeImageRequest;
use App\Http\Requests\Hotel\RoomTypeRequest;
use App\Http\Resources\RoomTypeResource;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function __construct(private readonly RoomTypeService $types) {}

    public function index(): JsonResponse
    {
        $types = RoomType::query()
            ->where('property_id', Property::current()->id)
            ->with(['amenities', 'images'])
            ->withCount('rooms')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(RoomTypeResource::collection($types));
    }

    public function store(RoomTypeRequest $request): JsonResponse
    {
        return ApiResponse::created(new RoomTypeResource($this->types->create($request->validated())), 'Room type created.');
    }

    public function show(RoomType $roomType): JsonResponse
    {
        return ApiResponse::success(new RoomTypeResource($roomType->load(['amenities', 'images'])->loadCount('rooms')));
    }

    public function update(RoomTypeRequest $request, RoomType $roomType): JsonResponse
    {
        return ApiResponse::success(new RoomTypeResource($this->types->update($roomType, $request->validated())), 'Room type updated.');
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        $this->types->delete($roomType);

        return ApiResponse::success(null, 'Room type deleted.');
    }

    public function storeImage(RoomTypeImageRequest $request, RoomType $roomType): JsonResponse
    {
        $this->types->addImage($roomType, $request->file('image'), $request->validated('alt'));

        return ApiResponse::created(new RoomTypeResource($roomType->load(['amenities', 'images'])->loadCount('rooms')), 'Photo uploaded.');
    }

    public function destroyImage(RoomType $roomType, RoomTypeImage $image): JsonResponse
    {
        $this->types->deleteImage($roomType, $image);

        return ApiResponse::success(new RoomTypeResource($roomType->load(['amenities', 'images'])->loadCount('rooms')), 'Photo removed.');
    }

    public function reorderImages(Request $request, RoomType $roomType): JsonResponse
    {
        $data = $request->validate(['image_ids' => ['required', 'array'], 'image_ids.*' => ['integer']]);
        $this->types->reorderImages($roomType, $data['image_ids']);

        return ApiResponse::success(new RoomTypeResource($roomType->load(['amenities', 'images'])->loadCount('rooms')), 'Photo order saved.');
    }
}
