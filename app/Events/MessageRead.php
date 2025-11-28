<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    public $senderId;
    public $recipientId;

    public function __construct($senderId, $recipientId)
    {
        $this->senderId = $senderId;
        $this->recipientId = $recipientId;
    }

    public function broadcastWith()
    {
        return [
            'sender_id' => $this->senderId,
            'recipient_id' => $this->recipientId,
        ];
    }

    public function broadcastOn()
    {
        // Broadcast to the sender's private channel so they see ticks change
        return new PrivateChannel('App.Models.User.' . $this->senderId);
    }

    public function broadcastAs()
    {
        return 'MessageRead';
    }
}
