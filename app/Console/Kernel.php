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
    // Tournament round processing is now event-driven via a TournamentBattle observer and queued job.
    // Removed the scheduled `tournaments:check-rounds` task to avoid scanning inactive tournaments.
    }

    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');
    }
}
