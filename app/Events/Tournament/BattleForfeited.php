<?php

namespace App\Events\Tournament;

use App\Models\TournamentBattle;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleForfeited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
                'forfeit_reason' => $this->battle->forfeit_reason,
                'battle_duration' => $this->battle->battle_duration
            ]
        ];
    }
}