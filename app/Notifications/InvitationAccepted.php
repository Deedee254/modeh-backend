<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationAccepted extends Notification
{
    use Queueable;

    protected $institution;
    protected $user;

    public function __construct($institution, $user)
    {
        $this->institution = $institution;
        $this->user = $user;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        $frontend = env('FRONTEND_URL', config('app.url'));
        return (new MailMessage)
                    ->subject('A user accepted an invitation')
                    ->line("{$this->user->name} ({$this->user->email}) has accepted an invitation to join {$this->institution->name}.")
                    ->action('View institution', $frontend . '/institution-manager/institutions/' . ($this->institution->slug ?? $this->institution->id))
                    ->line('Thank you for using Modeh!');
    }

    public function toDatabase($notifiable)
    {
        return [
            'type' => 'invitation_accepted',
            'institution_id' => $this->institution->id,
            'institution_name' => $this->institution->name,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'user_email' => $this->user->email,
        ];
    }
}
