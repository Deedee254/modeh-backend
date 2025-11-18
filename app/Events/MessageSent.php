<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use App\Models\Message;

class MessageSent implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    /** @var Message */
    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }

    public function broadcastWith()
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'recipient_id' => $this->message->recipient_id,
                'content' => $this->message->content,
                'group_id' => $this->message->group_id,
                'type' => $this->message->type,
                'is_read' => $this->message->is_read,
                'created_at' => optional($this->message->created_at)->toDateTimeString(),
            ],
        ];
    }

    public function broadcastOn()
    {
        if (!empty($this->message->group_id)) {
            return new PrivateChannel('App.Models.Group.' . $this->message->group_id);
        }

        // 1:1 message channel for recipient
        if (!empty($this->message->recipient_id)) {
            return new PrivateChannel('App.Models.User.' . $this->message->recipient_id);
        }

        // fallback to sender
        return new PrivateChannel('App.Models.User.' . $this->message->sender_id);
    }
}
