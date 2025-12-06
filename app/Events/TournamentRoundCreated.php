<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class TournamentRoundCreated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    /**
     * Tournament id
     * @var int
     */
    public $tournamentId;

    /**
     * Round number created
     * @var int
     */
    public $round;

    /**
     * Newly created battles (array or collection of TournamentBattle models)
     * @var array
     */
    public $battles;

    /**
     * Create a new event instance.
     *
     * @param int $tournamentId
     * @param int $round
     * @param array|\\Illuminate\\Support\\Collection $battles
     */
    public function __construct(int $tournamentId, int $round, $battles = [])
    {
        $this->tournamentId = $tournamentId;
        $this->round = $round;
        $this->battles = $battles;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('tournament.' . $this->tournamentId);
    }

    public function broadcastWith()
    {
        return [
            'tournament_id' => $this->tournamentId,
            'round' => $this->round,
            'battles' => $this->battles,
        ];
    }
}
