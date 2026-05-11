<?php

namespace App\Notifications;

use App\Models\Question;
use App\Models\QuestionFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class QuestionFlagged extends Notification
{
    use Queueable;

    public $question;
    public $flag;

    public function __construct(Question $question, QuestionFlag $flag)
    {
        $this->question = $question;
        $this->flag = $flag;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'type' => 'question_flagged',
            'title' => 'Question Flagged',
            'message' => "One of your questions has been flagged for review: \"{$this->question->body}\"",
            'question_id' => $this->question->id,
            'flag_id' => $this->flag->id,
            'reason' => $this->flag->reason,
            'action_url' => "/quiz-master/questions?filter=flagged",
        ];
    }
}
