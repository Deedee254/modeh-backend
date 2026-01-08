<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Models\Quiz;
use App\Models\User;

class QuizLikedNotification extends Notification
{
    use Queueable;

    public $quiz;
    public $liker;

    public function __construct(Quiz $quiz, User $liker)
    {
        $this->quiz = $quiz;
        $this->liker = $liker;
    }

    public function via($notifiable)
    {
        // Check per-user notification preferences
        try {
            $pref = \App\Models\NotificationPreference::where('user_id', $notifiable->id)->first();
            if ($pref && is_array($pref->preferences)) {
                // Check if user wants quiz like notifications
                if (isset($pref->preferences['quiz_likes']) && $pref->preferences['quiz_likes'] === false) {
                    return []; // Don't send any notifications
                }

                // preferences could be ['via' => ['database','broadcast']] or just the array of channels
                if (isset($pref->preferences['via']) && is_array($pref->preferences['via'])) {
                    return $pref->preferences['via'];
                }
                return $pref->preferences;
            }
        } catch (\Exception $e) {
            // if anything goes wrong, fall back to default
        }

        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'quiz_id' => $this->quiz->id,
            'quiz_title' => $this->quiz->title,
            'liker_id' => $this->liker->id,
            'liker_name' => $this->liker->name,
            'type' => 'quiz_liked',
            'title' => 'Your quiz was liked!',
            'body' => "{$this->liker->name} liked your quiz '{$this->quiz->title}'",
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'quiz_id' => $this->quiz->id,
            'quiz_title' => $this->quiz->title,
            'liker_id' => $this->liker->id,
            'liker_name' => $this->liker->name,
            'type' => 'quiz_liked',
            'title' => 'Your quiz was liked!',
            'body' => "{$this->liker->name} liked your quiz '{$this->quiz->title}'",
        ]);
    }
}