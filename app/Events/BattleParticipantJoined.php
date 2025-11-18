<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleParticipantJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    public $battle;
    public $userId;

    public function __construct($battle, $userId)
    {
        $this->battle = is_array($battle) ? $battle : $battle->toArray();
        $this->userId = $userId;
    }

    public function broadcastWith()
    {
        return ['battle' => $this->battle, 'user_id' => $this->userId];
    }

    public function broadcastOn()
    {
        // Broadcast to the battle's private channel for participants
        $id = $this->battle['uuid'] ?? $this->battle['id'] ?? null;
        return new PrivateChannel('battle.' . $id);
    }

    public function broadcastAs()
    {
        return 'BattleParticipantJoined';
    }
}
