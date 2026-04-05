<?php

namespace App\Console\Commands;

use App\Support\CommissionCollectionMonitor;
use Illuminate\Console\Command;

class ProcessOverdueCommissionCollections extends Command
{
    protected $signature = 'commissions:process-overdue';

    protected $description = 'Process overdue commission collections and send reminders to admin and restaurant owners.';

    public function handle(): int
    {
        $count = CommissionCollectionMonitor::processOverdueCollections();

        $this->info("Processed {$count} overdue commission collection(s).");

        return self::SUCCESS;
    }
}
