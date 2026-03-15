<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResourceRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public string $resourceType;
    public $resource;
    public ?string $reason;
    public $adminUser;

    public function __construct(string $resourceType, $resource, ?string $reason = null, $adminUser = null)
    {
        $this->resourceType = $resourceType;
        $this->resource = $resource;
        $this->reason = $reason;
        $this->adminUser = $adminUser;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $title = ucfirst($this->resourceType) . ' rejected';
        $reasonLine = $this->reason ? "Reason: {$this->reason}" : null;

        $id = $this->resource->id ?? '';

        $mail = (new MailMessage)
            ->subject("Your {$this->resourceType} was rejected")
            ->greeting('Hello ' . ($notifiable->name ?? ''))
            ->line("Your {$this->resourceType} (ID: {$id}) was reviewed by an administrator and marked as rejected.")
            ;

        if ($reasonLine) {
            $mail->line($reasonLine);
        }

        $mail->line('If you believe this was a mistake, please contact support or review the submission and resubmit after addressing the feedback.')
            ->salutation('Regards,')
            ->salutation(config('app.name'));

        return $mail;
    }
}
