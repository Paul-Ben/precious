<?php

namespace App\Domain\Property;

use App\Domain\Audit\AuditService;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hotel operating rules: config('hotel.defaults') overridden by the
 * property's rows in `settings`. Read everywhere through this class so a
 * rule is defined in exactly one place.
 */
class HotelSettings
{
    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function all(?Property $property = null): array
    {
        $property ??= Property::current();

        return $this->cache[$property->id] ??= array_merge(
            config('hotel.defaults'),
            Setting::query()
                ->where('property_id', $property->id)
                ->whereIn('key', array_keys(config('hotel.defaults')))
                ->pluck('value', 'key')
                ->all()
        );
    }

    public function get(string $key, ?Property $property = null): mixed
    {
        return $this->all($property)[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $values  keys from config('hotel.defaults')
     * @return array<string, mixed>
     */
    public function update(array $values, User $actor, ?Property $property = null): array
    {
        $property ??= Property::current();
        $before = $this->all($property);
        $allowed = array_intersect_key($values, config('hotel.defaults'));

        DB::transaction(function () use ($property, $allowed, $actor, $before) {
            foreach ($allowed as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['property_id' => $property->id, 'key' => $key],
                    ['value' => $value, 'updated_by' => $actor->id],
                );
            }

            unset($this->cache[$property->id]);
            [$old, $new] = $this->audit->diff($before, $this->all($property));

            if ($new !== []) {
                $this->audit->record('settings.hotel_policies_updated', $property, $old, $new);
            }
        });

        return $this->all($property);
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** Today's date in the hotel's time zone. */
    public function today(?Property $property = null): CarbonImmutable
    {
        $property ??= Property::current();

        return CarbonImmutable::now($property->timezone)->startOfDay();
    }

    /** A date plus a "HH:MM" setting, in the hotel's time zone. */
    public function at(CarbonImmutable $date, string $settingKey, ?Property $property = null): CarbonImmutable
    {
        $property ??= Property::current();
        [$h, $m] = array_map('intval', explode(':', (string) $this->get($settingKey, $property)));

        return CarbonImmutable::create($date->year, $date->month, $date->day, $h, $m, 0, $property->timezone);
    }
}
