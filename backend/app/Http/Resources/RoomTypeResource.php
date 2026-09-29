<?php

namespace App\Http\Resources;

use App\Models\RoomType;
use App\Models\RoomTypeImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoomType
 */
class RoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->relationLoaded('images') ? $this->images : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'base_rate' => $this->base_rate,
            'currency' => config('hotel.currency'),
            'max_adults' => $this->max_adults,
            'max_children' => $this->max_children,
            'max_occupancy' => $this->max_occupancy,
            'bed_type' => $this->bed_type,
            'size_sqm' => $this->size_sqm,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'rooms_count' => $this->whenCounted('rooms'),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'images' => $this->whenLoaded('images', fn () => $images->map(fn (RoomTypeImage $img) => [
                'id' => $img->id,
                'url' => $img->url(),
                'alt' => $img->alt,
                'sort_order' => $img->sort_order,
            ])->values()),
            'cover_image_url' => $images->first()?->url(),
        ];
    }
}
