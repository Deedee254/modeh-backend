<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Run prune command daily at 02:00
        $schedule->command('metrics:prune-buckets')->dailyAt('02:00');
        // Process tournaments every 10 minutes to auto-finalize simple-flow winners
        $schedule->command('tournaments:process-rounds')->everyTenMinutes();
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
    }
}
