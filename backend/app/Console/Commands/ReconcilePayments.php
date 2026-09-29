<?php

namespace App\Console\Commands;

use App\Domain\Payments\PaymentService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Check pending online payments with the gateway and abandon very old ones';

    public function handle(PaymentService $payments): int
    {
        $result = $payments->reconcile();

        $this->info("Verified {$result['verified']} pending payment(s); abandoned {$result['abandoned']}.");

        return self::SUCCESS;
    }
}
