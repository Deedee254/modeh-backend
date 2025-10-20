<?php

namespace App\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use App\Models\QuizMaster;
use App\Models\User;

class QuizMasterFollowed implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $quizMaster;
    public $user;

    public function __construct(QuizMaster $quizMaster, User $user)
    {
        $this->quizMaster = $quizMaster->only(['id', 'name', 'avatar']);
        $this->user = $user->only(['id', 'name', 'avatar']);
    }

    public function broadcastOn()
    {
        return new PrivateChannel('quiz-master.' . $this->quizMaster['id']);
    }
}
