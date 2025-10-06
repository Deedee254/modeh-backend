<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast as ShouldBroadcastContract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BattleResult implements ShouldBroadcastContract
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $battle;

    public function __construct(int $userId, array $battle)
    {
        $this->userId = $userId;
        $this->battle = $battle;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastWith()
    {
        return ['battle' => $this->battle, 'user_id' => $this->userId];
    }

    public function broadcastAs()
    {
        return 'BattleResult';
    }
}
