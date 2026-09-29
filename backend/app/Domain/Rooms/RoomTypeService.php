<?php

namespace App\Domain\Rooms;

use App\Domain\Audit\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoomTypeService
{
    private const AUDITED = [
        'name', 'slug', 'short_description', 'description', 'base_rate', 'max_adults',
        'max_children', 'max_occupancy', 'bed_type', 'size_sqm', 'is_active', 'sort_order',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RoomType
    {
        $data = $this->normaliseRate($data);

        return DB::transaction(function () use ($data) {
            $property = Property::current();
            $amenities = $data['amenity_ids'] ?? [];
            unset($data['amenity_ids']);

            $type = RoomType::create([
                ...$data,
                'property_id' => $property->id,
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name'], $property->id),
            ]);
            $type->amenities()->sync($amenities);

            $this->audit->record('room_types.created', $type, null, [
                ...$type->only(self::AUDITED),
                'amenity_ids' => array_values($amenities),
            ]);

            return $type->load(['amenities', 'images'])->loadCount('rooms');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RoomType $type, array $data): RoomType
    {
        $data = $this->normaliseRate($data);

        return DB::transaction(function () use ($type, $data) {
            $before = [...$type->only(self::AUDITED), 'amenity_ids' => $type->amenities()->pluck('amenities.id')->sort()->values()->all()];

            if (array_key_exists('amenity_ids', $data)) {
                $type->amenities()->sync($data['amenity_ids']);
            }
            unset($data['amenity_ids']);

            if (isset($data['slug']) && $data['slug'] !== $type->slug) {
                $data['slug'] = $this->uniqueSlug($data['slug'], $type->property_id, $type->id);
            }

            $type->fill($data)->save();

            $after = [...$type->only(self::AUDITED), 'amenity_ids' => $type->amenities()->pluck('amenities.id')->sort()->values()->all()];
            [$old, $new] = $this->audit->diff($before, $after);

            if ($new !== []) {
                $this->audit->record('room_types.updated', $type, $old, $new);
            }

            return $type->load(['amenities', 'images'])->loadCount('rooms');
        });
    }

    public function delete(RoomType $type): void
    {
        if ($type->rooms()->exists()) {
            throw new BusinessRuleException(
                'This room type still has rooms. Move or delete the rooms, or deactivate the room type instead.',
                'ROOM_TYPE_IN_USE'
            );
        }

        DB::transaction(function () use ($type) {
            $type->delete();
            $this->audit->record('room_types.deleted', $type, $type->only(['name', 'slug']), null);
        });
    }

    public function addImage(RoomType $type, UploadedFile $file, ?string $alt): RoomTypeImage
    {
        $disk = config('hotel.media_disk');
        $path = $file->storePublicly("room-types/{$type->id}", ['disk' => $disk]);

        return DB::transaction(function () use ($type, $disk, $path, $alt) {
            $image = $type->images()->create([
                'disk' => $disk,
                'path' => $path,
                'alt' => $alt ?: $type->name,
                'sort_order' => (int) $type->images()->max('sort_order') + 1,
            ]);

            $this->audit->record('room_types.image_added', $type, null, ['image_id' => $image->id]);

            return $image;
        });
    }

    public function deleteImage(RoomType $type, RoomTypeImage $image): void
    {
        abort_unless($image->room_type_id === $type->id, 404);

        DB::transaction(function () use ($type, $image) {
            $image->delete();
            $this->audit->record('room_types.image_removed', $type, ['image_id' => $image->id], null);
        });

        Storage::disk($image->disk)->delete($image->path);
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderImages(RoomType $type, array $orderedIds): void
    {
        DB::transaction(function () use ($type, $orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                $type->images()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseRate(array $data): array
    {
        if (isset($data['base_rate'])) {
            $data['base_rate'] = Money::toDecimal(Money::toMinor((string) $data['base_rate']));
        }

        return $data;
    }

    private function uniqueSlug(string $source, int $propertyId, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'room';
        $slug = $base;
        $i = 2;

        while (RoomType::withTrashed()
            ->where('property_id', $propertyId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
