<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast as ShouldBroadcastContract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BadgeAwarded implements ShouldBroadcastContract
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $badge;

    public function __construct(int $userId, array $badge)
    {
        $this->userId = $userId;
        $this->badge = $badge;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastWith()
    {
        return ['badge' => $this->badge, 'user_id' => $this->userId];
    }

    public function broadcastAs()
    {
        return 'BadgeAwarded';
    }
}
