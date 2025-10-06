<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class NewMessageNotification extends Notification
{
    use Queueable;

    public $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function via($notifiable)
    {
        // Check per-user notification preferences (Option A)
        try {
            $pref = \App\Models\NotificationPreference::where('user_id', $notifiable->id)->first();
            if ($pref && is_array($pref->preferences)) {
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
            'message_id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'content' => $this->message->content,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'message' => [
                'id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'content' => $this->message->content,
                'created_at' => $this->message->created_at,
            ]
        ]);
    }
}
