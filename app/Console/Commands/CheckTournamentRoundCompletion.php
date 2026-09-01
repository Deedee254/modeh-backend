<?php

namespace App\Console\Commands;

use App\Models\Tournament;
use Illuminate\Console\Command;

class CheckTournamentRoundCompletion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tournaments:check-rounds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check active tournaments for completed rounds and generate next rounds when needed';

    public function handle()
    {
        // Deprecated: round-based tournament processing has been removed.
        $this->info('tournaments:check-rounds is deprecated. Tournament flow is now qualifier-only.');

        return 0;
    }
}
