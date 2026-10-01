<?php

namespace App\Domain\Finance;

use App\Domain\Property\HotelSettings;
use App\Exceptions\BusinessRuleException;
use App\Models\DailyClosing;
use App\Models\Property;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * P33: once a day is closed, its desk payments and cash expenses are frozen.
 * Corrections go into the next open day.
 *
 * Closing and the frozen writes are serialised with a PostgreSQL advisory lock:
 * closing takes it exclusively, writers take it shared inside their own
 * transaction, so a payment can never commit into a day after its closing
 * snapshot was taken.
 */
class DayLock
{
    /** Advisory lock class id (first key of pg_advisory_xact_lock(int, int)). */
    private const LOCK_CLASS = 33_0601;

    public function __construct(private readonly HotelSettings $settings) {}

    public function isClosed(DateTimeInterface|string $date): bool
    {
        return DailyClosing::query()
            ->where('property_id', Property::current()->id)
            ->where('date', $this->day($date))
            ->where('status', DailyClosing::CLOSED)
            ->exists();
    }

    /** Call inside the writer's transaction. */
    public function assertOpen(DateTimeInterface|string $date, string $what = 'This'): void
    {
        $this->lock(shared: true);

        if ($this->isClosed($date)) {
            throw new BusinessRuleException(
                "{$what} falls on {$this->day($date)}, which has been closed. Record the correction on an open day, or ask an administrator to reopen it.",
                'DAY_CLOSED',
                409,
            );
        }
    }

    /** Desk payments and cash refunds happen "now": today must be open. */
    public function assertTodayOpen(string $what): void
    {
        $this->assertOpen($this->settings->today(), $what);
    }

    /** Held until the surrounding transaction ends (no-op outside one or off PostgreSQL). */
    public function lock(bool $shared = false): void
    {
        if (DB::transactionLevel() === 0 || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::select($shared ? 'SELECT pg_advisory_xact_lock_shared(?, ?)' : 'SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_CLASS, Property::current()->id]);
    }

    private function day(DateTimeInterface|string $date): string
    {
        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10);
    }
}
