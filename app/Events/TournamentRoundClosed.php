<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

/**
 * Event: TournamentRoundClosed
 * 
 * Fired when a tournament round is officially closed and results finalized.
 * This serves as a single source of truth for round closure, preventing race conditions
 * from multiple triggers (auto-submit, scheduler, manual advance).
 */
class TournamentRoundClosed implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    /**
     * Tournament ID
     * @var int
     */
    public $tournamentId;

    /**
     * Round number that was closed
     * @var int
     */
    public $round;

    /**
     * Winner IDs from this round
     * @var array
     */
    public $winners;

    /**
     * Next round number (null if tournament ended)
     * @var int|null
     */
    public $nextRound;

    /**
     * Whether tournament completed (no more rounds)
     * @var bool
     */
    public $tournamentComplete;

    /**
     * Final tournament winner if complete
     * @var int|null
     */
    public $tournamentWinner;

    /**
     * Create a new event instance.
     *
     * @param int $tournamentId
     * @param int $round
     * @param array $winners
     * @param int|null $nextRound
     * @param bool $tournamentComplete
     * @param int|null $tournamentWinner
     */
    public function __construct(
        int $tournamentId,
        int $round,
        array $winners = [],
        ?int $nextRound = null,
        bool $tournamentComplete = false,
        ?int $tournamentWinner = null
    ) {
        $this->tournamentId = $tournamentId;
        $this->round = $round;
        $this->winners = $winners;
        $this->nextRound = $nextRound;
        $this->tournamentComplete = $tournamentComplete;
        $this->tournamentWinner = $tournamentWinner;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('tournaments'),
            new PrivateChannel('tournament.' . $this->tournamentId),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tournament_id' => $this->tournamentId,
            'round' => $this->round,
            'winners' => $this->winners,
            'next_round' => $this->nextRound,
            'tournament_complete' => $this->tournamentComplete,
            'tournament_winner' => $this->tournamentWinner,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
