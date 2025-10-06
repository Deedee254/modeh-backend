<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast as ShouldBroadcastContract;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyChallengeCompleted implements ShouldBroadcastContract
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $challenge;

    public function __construct(int $userId, array $challenge)
    {
        $this->userId = $userId;
        $this->challenge = $challenge;
    }

    public function broadcastOn()
    {
        return new PrivateChannel("user.{$this->userId}");
    }

    public function broadcastWith()
    {
        return ['challenge' => $this->challenge, 'user_id' => $this->userId];
    }

    public function broadcastAs()
    {
        return 'DailyChallengeCompleted';
    }
}
