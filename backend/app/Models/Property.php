<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The hotel. The platform runs one property for now (ASSUMPTIONS P12), but
 * every table keeps property_id so more can be added later.
 */
class Property extends Model
{
    protected $fillable = [
        'name', 'slug', 'legal_name', 'email', 'phone', 'address', 'city', 'state',
        'country', 'timezone', 'currency', 'description',
    ];

    public const CURRENT = 'property.current';

    /**
     * The single active property. Resolved once per request / queued job
     * (a "scoped" container binding registered in AppServiceProvider).
     */
    public static function current(): self
    {
        return app(self::CURRENT);
    }

    public static function forgetCurrent(): void
    {
        app()->forgetInstance(self::CURRENT);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function roomTypes(): HasMany
    {
        return $this->hasMany(RoomType::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
