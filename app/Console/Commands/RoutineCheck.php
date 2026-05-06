<?php

namespace App\Console\Commands;

use App\Services\RoutineScheduler;
use Illuminate\Console\Command;

class RoutineCheck extends Command
{
    protected $signature = 'routine:check';
    protected $description = 'Check for due routines and dispatch them';

    public function handle(RoutineScheduler $scheduler): void
    {
        $this->info('Checking routines...');

        // Sync statuses of running routines
        $scheduler->syncRunStatuses();

        // Dispatch due routines
        $dispatched = $scheduler->checkAndDispatch();

        $this->info("Dispatched {$dispatched} routine(s).");
    }
}
