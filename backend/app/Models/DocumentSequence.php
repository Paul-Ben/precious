<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentSequence extends Model
{
    protected $fillable = ['prefix', 'year', 'last_value'];

    /**
     * Next gapless number for a prefix and year, e.g. next('RES', 2026, 5) →
     * "RES-2026-00001". Must be called inside the transaction that uses it,
     * so a rolled-back booking also rolls back its number.
     */
    public static function next(string $prefix, int $year, int $pad = 5): string
    {
        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) static::nextValue($prefix, $year), $pad, '0', STR_PAD_LEFT));
    }

    /** Next raw counter value for (prefix, year); year 0 = a sequence that never resets. */
    public static function nextValue(string $prefix, int $year = 0): int
    {
        return DB::transaction(function () use ($prefix, $year) {
            static::query()->insertOrIgnore([
                'prefix' => $prefix,
                'year' => $year,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = static::query()
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $row->increment('last_value');

            return (int) $row->last_value;
        });
    }
}
