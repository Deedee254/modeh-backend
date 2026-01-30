<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit and is queued
    public $afterCommit = true;

    public $userId;
    public $subscription;
    public $tx;

    public function __construct($userId, $subscription, $tx = null)
    {
        $this->userId = $userId;
        $this->subscription = $subscription;
        $this->tx = $tx;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('users.'.$this->userId);
    }

    public function broadcastWith()
    {
        return ['subscription' => $this->subscription, 'tx' => $this->tx, 'user_id' => $this->userId];
    }
}
