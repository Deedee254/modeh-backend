<?php

namespace App\Notifications;

use App\Models\Question;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class QuestionAutoUnapproved extends Notification
{
    use Queueable;

    public $question;

    public function __construct(Question $question)
    {
        $this->question = $question;
    }

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    public function toArray($notifiable)
    {
        return [
            'type' => 'question_auto_unapproved',
            'title' => 'Question Automatically Unapproved',
            'message' => "Your question \"{$this->question->body}\" has been automatically unapproved due to multiple student reports. Please review and correct it.",
            'question_id' => $this->question->id,
            'action_url' => "/quiz-master/questions/{$this->question->id}/edit",
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Question Automatically Unapproved')
            ->line("Your question \"{$this->question->body}\" has been automatically unapproved due to multiple student reports.")
            ->action('Review Question', url(config('app.frontend_url') . "/quiz-master/questions/{$this->question->id}/edit"))
            ->line('Please ensure the question and answers are accurate to maintain your quality score.');
    }
}
