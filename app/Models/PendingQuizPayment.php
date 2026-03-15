<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingQuizPayment extends Model
{
    use HasFactory;

    protected $table = 'pending_quiz_payments';

    protected $fillable = [
        'quiz_master_id',
        'quizee_id',
        'quiz_id',
        'quiz_attempt_id',
        'amount',
        'status',
        'reminder_status',
        'attempt_at',
        'payment_due_at',
        'first_reminder_at',
        'last_reminder_at',
        'paid_at',
        'cancelled_at',
        'recovery_status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'attempt_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'first_reminder_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function quizMaster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quiz_master_id');
    }

    public function quizee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quizee_id');
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function quizAttempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    /**
     * Check if this payment is overdue
     */
    public function isOverdue(): bool
    {
        return $this->status === 'pending' && now()->isAfter($this->payment_due_at);
    }

    /**
     * Check if 24+ hours have passed since last reminder
     */
    public function shouldSendReminder(): bool
    {
        // First reminder: send if not sent yet
        if ($this->reminder_status === 'not_sent') {
            return true;
        }

        // Subsequent reminders: only if 24+ hours since last
        if ($this->last_reminder_at) {
            return now()->diffInHours($this->last_reminder_at) >= 24;
        }

        return false;
    }

    /**
     * Get reminder count (1-3)
     */
    public function getReminderCount(): int
    {
        if ($this->reminder_status === 'not_sent') return 0;
        
        preg_match('/sent_(\d+)/', $this->reminder_status, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }

    /**
     * Mark as paid and credit quiz master
     */
    public function markAsPaid(): void
    {
        \DB::transaction(function () {
            $this->status = 'paid';
            $this->paid_at = now();
            $this->save();

            // Credit quiz master's available balance
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $this->quiz_master_id],
                ['available' => 0, 'withdrawn_pending' => 0, 'lifetime_earned' => 0]
            );

            $wallet->available = bcadd($wallet->available, $this->amount, 2);
            $wallet->lifetime_earned = bcadd($wallet->lifetime_earned, $this->amount, 2);
            $wallet->earned_from_quizzes = bcadd($wallet->earned_from_quizzes ?? 0, $this->amount, 2);
            $wallet->earned_this_month = bcadd($wallet->earned_this_month ?? 0, $this->amount, 2);
            $wallet->save();

            // Create transaction record
            Transaction::create([
                'user_id' => $this->quiz_master_id,
                'quiz_id' => $this->quiz_id,
                'quiz_attempt_id' => $this->quiz_attempt_id,
                'pending_payment_id' => $this->id,
                'amount' => $this->amount,
                'type' => 'quiz_completion_payment',
                'payment_status' => 'paid',
                'status' => 'completed',
                'description' => "Payment received from {$this->quizee->name} for quiz",
            ]);

            // Broadcast wallet update
            event(new \App\Events\WalletUpdated($wallet->toArray(), $this->quiz_master_id));
        });
    }

    /**
     * Send a reminder (increments count and updates timestamp)
     */
    public function sendReminder(): void
    {
        $currentCount = $this->getReminderCount();
        $newCount = $currentCount + 1;

        $this->reminder_status = "sent_{$newCount}";
        $this->last_reminder_at = now();
        
        if ($currentCount === 0) {
            $this->first_reminder_at = now();
        }

        $this->save();
    }

    /**
     * Mark as overdue if past due date and status is pending
     */
    public function checkAndMarkOverdue(): void
    {
        if ($this->status === 'pending' && $this->isOverdue()) {
            $this->status = 'overdue';
            $this->save();
        }
    }
}
