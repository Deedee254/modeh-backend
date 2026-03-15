<?php

namespace App\Notifications;

use App\Models\PendingQuizPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingPaymentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public PendingQuizPayment $payment,
        public int $reminderNumber = 1
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $messages = [
            1 => "You have an outstanding payment for a quiz.",
            2 => "Reminder: Your quiz payment is still pending.",
            3 => "FINAL REMINDER: Your payment is due very soon!",
        ];

        $message = $messages[$this->reminderNumber] ?? $messages[3];

        return (new MailMessage)
            ->subject("Payment Reminder #{$this->reminderNumber}: {$this->payment->quiz->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line($message)
            ->line("Quiz: {$this->payment->quiz->title}")
            ->line("Quiz Master: {$this->payment->quizMaster->name}")
            ->line("Amount Due: KES {$this->payment->amount}")
            ->line("Due By: {$this->payment->payment_due_at->format('M d, Y')}")
            ->action('Pay Now & Access Results', route('checkout', ['slug' => $this->payment->quiz->slug, 'pending_id' => $this->payment->id]))
            ->line('Thank you for using Modeh!');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_reminder',
            'pending_payment_id' => $this->payment->id,
            'quiz_id' => $this->payment->quiz_id,
            'quiz_title' => $this->payment->quiz->title,
            'quiz_master_name' => $this->payment->quizMaster->name,
            'amount' => $this->payment->amount,
            'reminder_number' => $this->reminderNumber,
            'message' => "Payment reminder for {$this->payment->quiz->title}",
            'checkout_url' => route('checkout', ['slug' => $this->payment->quiz->slug, 'pending_id' => $this->payment->id]),
        ];
    }
}
