<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tournament;
use App\Models\TournamentQualificationAttempt;

class ProcessTournamentRounds extends Command
{
    protected $signature = 'tournaments:process-rounds {--force : Force closure regardless of scheduled end dates}';
    protected $description = 'Process tournament rounds and finalize ended simple tournaments';

    public function handle()
    {
        $this->info('Scanning tournaments for round processing and simple-flow finalization...');

        $query = Tournament::whereIn('status', ['upcoming', 'active'])->get();

        foreach ($query as $t) {
            try {
                $current = $t->getCurrentRound();
                if ($current <= 0) {
                    if ($this->finalizeSimpleFlowTournament($t)) {
                        $this->info("Tournament {$t->id}: finalized simple flow winner");
                    }
                    continue;
                }

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

    private function finalizeSimpleFlowTournament(Tournament $tournament): bool
    {
        if ($tournament->status === 'completed') {
            return false;
        }

        if ($tournament->battles()->exists()) {
            return false;
        }

        if (!$tournament->end_date || now()->lt($tournament->end_date)) {
            return false;
        }

        $latestIdsSub = TournamentQualificationAttempt::query()
            ->selectRaw('MAX(id) as id')
            ->where('tournament_id', $tournament->id)
            ->groupBy('user_id');

        $winnerAttempt = TournamentQualificationAttempt::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('id', $latestIdsSub)
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->first();

        $tournament->status = 'completed';
        $tournament->winner_id = $winnerAttempt?->user_id;
        $tournament->save();

        return true;
    }
}
