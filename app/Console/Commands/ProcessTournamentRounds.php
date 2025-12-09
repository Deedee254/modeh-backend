<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tournament;

class ProcessTournamentRounds extends Command
{
    protected $signature = 'tournaments:process-rounds {--force : Force closure regardless of scheduled end dates}';
    protected $description = 'Process tournament rounds: close rounds whose end-date passed and auto-advance winners';

    public function handle()
    {
        $this->info('Scanning active tournaments for rounds to process...');

        $query = Tournament::where('status', 'active')->get();

        foreach ($query as $t) {
            try {
                $current = $t->getCurrentRound();
                if ($current <= 0) continue;

                $res = $t->closeRoundAndAdvance($current, (bool) $this->option('force'));
                if (!empty($res['ok']) && $res['ok'] === true) {
                    $this->info("Tournament {$t->id}: processed round {$current} -> next: " . ($res['next_round'] ?? 'n/a'));
                } else {
                    $this->info("Tournament {$t->id}: no action ({$res['message']})");
                }
            } catch (\Throwable $e) {
                \Log::error('Failed processing tournament rounds for ' . $t->id . ': ' . $e->getMessage());
                $this->error('Failed processing tournament ' . $t->id . ': ' . $e->getMessage());
            }
        }

        return 0;
    }
}
