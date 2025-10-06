<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class GroupMembershipChanged implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $groupId;
    public $action; // 'created', 'member_added', 'member_removed'
    public $members; // array of member ids/emails

    public function __construct($groupId, $action, $members = [])
    {
        $this->groupId = $groupId;
        $this->action = $action;
        $this->members = $members;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('group.' . $this->groupId);
    }

    public function broadcastWith()
    {
        return [
            'group_id' => $this->groupId,
            'action' => $this->action,
            'members' => $this->members,
        ];
    }
}
