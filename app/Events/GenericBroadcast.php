<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast as ShouldBroadcastContract;
use Illuminate\Queue\SerializesModels;

class GenericBroadcast implements ShouldBroadcastContract
{
    use InteractsWithSockets, SerializesModels;

    public $payload;
    protected $channelName;
    protected $isPrivate = false;
    protected $isPresence = false;

    public function __construct(string $channelName, array $payload = [], bool $isPrivate = false, bool $isPresence = false)
    {
        $this->channelName = $channelName;
        $this->payload = $payload;
        $this->isPrivate = $isPrivate;
        $this->isPresence = $isPresence;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn()
    {
        if ($this->isPresence) {
            return new PresenceChannel($this->channelName);
        }

        if ($this->isPrivate) {
            return new PrivateChannel($this->channelName);
        }

        return new Channel($this->channelName);
    }

    public function broadcastWith()
    {
        return $this->payload;
    }
}
