<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SubscriptionStatusNotification extends Notification
{
    use Queueable;

    protected $subscription;

    protected $message;

    public function __construct($subscription, $message = '')
    {
        $this->subscription = $subscription;
        $this->message = $message ?: 'Subscription updated';
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'subscription_id' => $this->subscription->id,
            'status' => $this->subscription->status,
            'package' => $this->subscription->package ? $this->subscription->package->title : null,
            'tx' => $this->subscription->gateway_meta['tx'] ?? null,
            'message' => $this->message,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'subscription_id' => $this->subscription->id,
            'status' => $this->subscription->status,
            'package' => $this->subscription->package ? $this->subscription->package->title : null,
            'tx' => $this->subscription->gateway_meta['tx'] ?? null,
            'message' => $this->message,
        ]);
    }
}
