<?php

namespace App\Events;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class QuizLiked implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    // Ensure broadcasting happens after DB transaction commit
    public $afterCommit = true;

    public $quiz;

    public $user;

    public function __construct(Quiz $quiz, User $user)
    {
        $this->quiz = $quiz->only(['id', 'title']);
        $this->user = $user->only(['id', 'name', 'avatar']);
    }

    public function broadcastOn()
    {
        // Broadcast globally or to quiz owner channel as needed
        return new PrivateChannel('quiz.'.$this->quiz['id']);
    }
}
