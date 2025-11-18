<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $battle;
    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    public function __construct($battle)
    {
        $this->battle = is_array($battle) ? $battle : $battle->toArray();
    }

    public function broadcastWith()
    {
        return ['battle' => $this->battle, 'status' => $this->battle['status'] ?? null];
    }

    public function broadcastOn()
    {
        // Broadcast to the battle's private channel for participants
        $id = $this->battle['uuid'] ?? $this->battle['id'] ?? null;
        return new PrivateChannel('battle.' . $id);
    }

    public function broadcastAs()
    {
        return 'BattleStatusUpdated';
    }
}
