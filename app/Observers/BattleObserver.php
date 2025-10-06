<?php

namespace App\Observers;

use App\Models\Battle;
use App\Events\BattleResult;
use App\Models\Notification as AppNotification;

class BattleObserver
{
    public function created(Battle $battle)
    {
        // created but may not have winner yet
    }

    public function updated(Battle $battle)
    {
        // when a battle gets completed and has a winner, broadcast to both participants
        try {
            if ($battle->winner_id) {
                $data = $battle->toArray();
                // notify winner and loser
                try {
                    AppNotification::create([
                        'user_id' => $battle->winner_id,
                        'type' => 'battle_result',
                        'data' => ['battle' => $data],
                    ]);
                } catch (\Exception $__e) {}

                $loser = $battle->initiator_id === $battle->winner_id ? $battle->opponent_id : $battle->initiator_id;
                if ($loser) {
                    try {
                        AppNotification::create([
                            'user_id' => $loser,
                            'type' => 'battle_result',
                            'data' => ['battle' => $data],
                        ]);
                    } catch (\Exception $__e) {}
                }

                event(new BattleResult($battle->winner_id, $data));
                if ($loser) event(new BattleResult($loser, $data));
            }
        } catch (\Exception $e) {
            // ignore
        }
    }
}
