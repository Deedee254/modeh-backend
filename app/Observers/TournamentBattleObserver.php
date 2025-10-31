<?php

namespace App\Observers;

use App\Models\TournamentBattle;
use App\Jobs\HandleTournamentRoundCompletion;

class TournamentBattleObserver
{
    /**
     * Handle the TournamentBattle "updated" event.
     */
    public function updated(TournamentBattle $battle): void
    {
        // If a battle just transitioned to completed and has a winner, trigger processing for that tournament
        $originalStatus = $battle->getOriginal('status');
        $currentStatus = $battle->status;

        if ($currentStatus === 'completed' && $originalStatus !== 'completed' && ! is_null($battle->winner_id)) {
            // Dispatch a queued job to process the tournament for next-round creation.
            HandleTournamentRoundCompletion::dispatch($battle->tournament_id);
        }
    }
}
