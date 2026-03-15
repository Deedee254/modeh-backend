<?php

namespace App\Services;

use App\Models\PendingQuizPayment;
use App\Models\User;
use App\Notifications\PendingPaymentReminderNotification;
use Illuminate\Support\Facades\DB;

class ReminderService
{
    /**
     * Check and send due reminders (24hr logic)
     * Called from any API endpoint to check if reminders are due
     */
    public static function checkAndSendDueReminders(): array
    {
        $sentCount = 0;
        $reminderData = [];

        // Get all pending payments that need reminders
        $pendingPayments = PendingQuizPayment::where('status', 'pending')
            ->where(function ($query) {
                $query->where('reminder_status', 'not_sent')
                    ->orWhere(DB::raw('TIMESTAMPDIFF(HOUR, last_reminder_at, NOW())'), '>=', 24);
            })
            ->with(['quizee', 'quizMaster', 'quiz'])
            ->get();

        /** @var PendingQuizPayment $payment */
        foreach ($pendingPayments as $payment) {
            if ($payment->shouldSendReminder()) {
                $reminderCount = $payment->getReminderCount() + 1;

                // Send notification
                try {
                    if ($payment->quizee) {
                        $payment->quizee->notify(
                            new PendingPaymentReminderNotification($payment, $reminderCount)
                        );
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to send notification: ' . $e->getMessage());
                }

                // Send chat message
                self::sendPaymentReminderMessage($payment, $reminderCount);

                // Update reminder status
                $payment->sendReminder();

                $sentCount++;
                $reminderData[] = [
                    'id' => $payment->id,
                    'quizee' => $payment->quizee?->name,
                    'quiz' => $payment->quiz?->title,
                    'amount' => $payment->amount,
                    'reminder_number' => $reminderCount,
                ];
            }

            // Check and mark as overdue if needed
            $payment->checkAndMarkOverdue();
        }

        return [
            'sent_count' => $sentCount,
            'reminders' => $reminderData,
        ];
    }

    /**
     * Send payment reminder via chat (inbox)
     */
    public static function sendPaymentReminderMessage(PendingQuizPayment $payment, int $reminderNumber): void
    {
        $quizee = $payment->quizee;
        $quizMaster = $payment->quizMaster;
        $quiz = $payment->quiz;

        $reminderMessages = [
            1 => "Hi {$quizee->name}, you have an outstanding payment for \"{$quiz->title}\" from {$quizMaster->name}. Amount due: KES {$payment->amount}. Payment due by: {$payment->payment_due_at->format('M d, Y')}.",
            2 => "Reminder: Your payment for \"{$quiz->title}\" is still pending. {$quizMaster->name} is waiting. KES {$payment->amount} due by {$payment->payment_due_at->format('M d, Y')}.",
            3 => "FINAL REMINDER: Payment for \"{$quiz->title}\" is due very soon! {$payment->payment_due_at->format('M d, Y')} Payment needed: KES {$payment->amount}",
        ];

        $message = $reminderMessages[$reminderNumber] ?? $reminderMessages[3];

        // Create notification entry for inbox message
        DB::table('notifications')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\PaymentReminder',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $quizee->id,
            'data' => json_encode([
                'subject' => "Payment Reminder: {$quiz->title}",
                'message' => $message,
                'pending_payment_id' => $payment->id,
                'reminder_number' => $reminderNumber,
                'amount' => (float)$payment->amount,
                'quiz_id' => $quiz->id,
                'quiz_master_id' => $quizMaster->id,
                'action_link' => "/checkout/{$quiz->slug}?pending_id={$payment->id}",
                'results_link' => "/quiz-attempts/{$payment->quiz_attempt_id}",
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Send custom message from quiz master to quizee
     */
    public static function sendQuizMasterReminder(PendingQuizPayment $payment, ?string $customMessage = null): void
    {
        $quizMaster = $payment->quizMaster;
        $quiz = $payment->quiz;

        $message = $customMessage ?? 
            "Hi {$payment->quizee->name}, just reminding you about the pending payment for \"{$quiz->title}\". " .
            "Amount: KES {$payment->amount}. Please complete payment to access your results.";

        // Create notification entry for inbox message
        DB::table('notifications')->insert([
            'id' => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\QuizMasterReminder',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $payment->quizee->id,
            'data' => json_encode([
                'subject' => "Reminder from {$quizMaster->name}",
                'message' => $message,
                'pending_payment_id' => $payment->id,
                'from_quiz_master' => true,
                'quiz_master_id' => $quizMaster->id,
                'quiz_id' => $quiz->id,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
