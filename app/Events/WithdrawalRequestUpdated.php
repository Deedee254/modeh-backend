<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class WithdrawalRequestUpdated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $withdrawal;
    public $userId;

    public function __construct($userId, $withdrawal)
    {
        $this->withdrawal = $withdrawal;
        $this->userId = $userId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('user.' . $this->userId);
    }

    public function broadcastWith()
    {
        return ['withdrawal_request' => $this->withdrawal, 'user_id' => $this->userId];
    }

    public function broadcastAs()
    {
        return 'WithdrawalRequestUpdated';
    }
}
