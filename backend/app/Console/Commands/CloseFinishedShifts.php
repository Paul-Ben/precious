<?php

namespace App\Console\Commands;

use App\Domain\Staff\ShiftService;
use Illuminate\Console\Command;

class CloseFinishedShifts extends Command
{
    protected $signature = 'shifts:close-attendance';

    protected $description = 'Mark staff who never clocked in as absent and close forgotten clock-outs (P26)';

    public function handle(ShiftService $shifts): int
    {
        $result = $shifts->closeFinishedShifts();
        $this->info("Marked {$result['absent']} absent, closed {$result['closed']} open clock-in(s).");

        return self::SUCCESS;
    }
}
