<?php

namespace App\Events\Tournament;

use App\Models\TournamentBattle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    public $battle;

    public function __construct(TournamentBattle $battle)
    {
        $this->battle = $battle;
    }

    public function broadcastOn()
    {
        return new PresenceChannel('tournament.' . $this->battle->tournament_id);
    }

    public function broadcastWith()
    {
        return [
            'battle' => [
                'id' => $this->battle->id,
                'status' => $this->battle->status,
                'completed_at' => $this->battle->completed_at,
                'winner_id' => $this->battle->winner_id,
                'is_draw' => $this->battle->is_draw,
                'player1_score' => $this->battle->player1_score,
                'player2_score' => $this->battle->player2_score,
                'battle_duration' => $this->battle->battle_duration
            ]
        ];
    }
}