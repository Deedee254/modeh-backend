<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tournament;
use App\Models\TournamentQualificationAttempt;

class ProcessTournamentRounds extends Command
{
    protected $signature = 'tournaments:process-rounds';
    protected $description = 'Finalize ended simple-flow tournaments';

    public function handle()
    {
        $this->info('Scanning tournaments for simple-flow finalization...');

        /** @var \Illuminate\Database\Eloquent\Collection<int, Tournament> $tournaments */
        $tournaments = Tournament::query()
            ->whereIn('status', ['upcoming', 'active'])
            ->get();

        foreach ($tournaments as $t) {
            if (!$t instanceof Tournament) {
                continue;
            }

            try {
                if ($this->finalizeSimpleFlowTournament($t)) {
                    $this->info("Tournament {$t->id}: finalized simple flow winner");
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
