<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wallet_type',
        'available',
        'pending',
        'withdrawn_pending',
        'settled',
        'lifetime_earned',
        'earned_this_month',
        'total_withdrawn',
        'earned_from_quizzes',
        'earned_from_affiliates',
        'earned_from_tournaments',
        'earned_from_battles',
        'earned_from_subscriptions',
        'refunded',
        'status',
    ];

    protected $casts = [
        'available' => 'decimal:2',
        'pending' => 'decimal:2',
        'withdrawn_pending' => 'decimal:2',
        'settled' => 'decimal:2',
        'lifetime_earned' => 'decimal:2',
        'earned_this_month' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
        'earned_from_quizzes' => 'decimal:2',
        'earned_from_affiliates' => 'decimal:2',
        'earned_from_tournaments' => 'decimal:2',
        'earned_from_battles' => 'decimal:2',
        'earned_from_subscriptions' => 'decimal:2',
        'refunded' => 'decimal:2',
    ];

    /**
     * Wallet type constants
     */
    public const TYPE_PLATFORM = 'platform';          // Platform operating wallet (pays everyone)
    public const TYPE_ADMIN = 'admin';                // Admin personal wallet (platform revenue after payouts)
    public const TYPE_QUIZ_MASTER = 'quiz_master';    // Quiz creator earnings
    public const TYPE_QUIZEE = 'quizee';              // Regular user earnings

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'wallet_id');
    }

    public function pendingPayments()
    {
        return $this->hasMany(PendingQuizPayment::class, 'quiz_master_id');
    }

    /**
     * Get total balance (available + withdrawn_pending)
     */
    public function getTotalBalanceAttribute(): string
    {
        return bcadd($this->available ?? 0, $this->withdrawn_pending ?? 0, 2);
    }

    /**
     * Get pending payments summary for quiz master
     */
    public function getPendingPaymentsSummary(): array
    {
        $pending = $this->pendingPayments()
            ->where('status', 'pending')
            ->get();

        return [
            'count' => $pending->count(),
            'total' => (float)$pending->sum('amount'),
            'details' => $pending,
        ];
    }

    /**
     * Get overdue payments
     */
    public function getOverduePayments()
    {
        return $this->pendingPayments()
            ->where('status', 'overdue')
            ->get();
    }

    /**
     * Request withdrawal (move available to withdrawn_pending)
     */
    public function requestWithdrawal(float $amount): void
    {
        if ($amount > $this->available) {
            throw new \Exception('Insufficient available balance');
        }

        $this->available = bcsub($this->available, $amount, 2);
        $this->withdrawn_pending = bcadd($this->withdrawn_pending, $amount, 2);
        $this->save();
    }

    /**
     * Confirm settlement (move withdrawn_pending to settled)
     */
    public function confirmSettlement(float $amount): void
    {
        if ($amount > $this->withdrawn_pending) {
            throw new \Exception('Invalid settlement amount');
        }

        $this->withdrawn_pending = bcsub($this->withdrawn_pending, $amount, 2);
        $this->settled = bcadd($this->settled, $amount, 2);
        $this->total_withdrawn = bcadd($this->total_withdrawn, $amount, 2);
        $this->save();
    }

    /**
     * Check if wallet is active (has user and valid type)
     */
    public function isActive(): bool
    {
        return $this->user_id && $this->wallet_type && in_array($this->wallet_type, [
            self::TYPE_PLATFORM,
            self::TYPE_ADMIN,
            self::TYPE_QUIZ_MASTER,
            self::TYPE_QUIZEE,
        ]);
    }

    /**
     * Get earnings breakdown by source
     */
    public function getEarningsBreakdown(): array
    {
        return [
            'from_quizzes' => (float)($this->earned_from_quizzes ?? 0),
            'from_affiliates' => (float)($this->earned_from_affiliates ?? 0),
            'from_tournaments' => (float)($this->earned_from_tournaments ?? 0),
            'from_battles' => (float)($this->earned_from_battles ?? 0),
            'from_subscriptions' => (float)($this->earned_from_subscriptions ?? 0),
            'total' => (float)($this->lifetime_earned ?? 0),
        ];
    }

    /**
     * Record earning from a specific source
     */
    public function recordEarning(string $source, float $amount, string $description = ''): Transaction
    {
        $field = match($source) {
            'quizzes' => 'earned_from_quizzes',
            'affiliates' => 'earned_from_affiliates',
            'tournaments' => 'earned_from_tournaments',
            'battles' => 'earned_from_battles',
            'subscriptions' => 'earned_from_subscriptions',
            default => null,
        };

        if ($field) {
            $this->$field = bcadd($this->{$field} ?? 0, $amount, 2); // @phpstan-ignore-line
            $this->lifetime_earned = bcadd($this->lifetime_earned ?? 0, $amount, 2); // @phpstan-ignore-line
            $this->pending = bcadd($this->pending ?? 0, $amount, 2); // @phpstan-ignore-line
            $this->save();

            // Record transaction
            return Transaction::create([
                'user_id' => $this->user_id,
                'amount' => $amount,
                'type' => "earning_{$source}",
                'status' => Transaction::STATUS_COMPLETED,
                'description' => $description ?: "Earning from {$source}",
                'meta' => [
                    'source' => $source,
                    'wallet_type' => $this->wallet_type,
                ],
            ]);
        }

        throw new \Exception("Invalid earning source: {$source}");
    }

    /**
     * Record withdrawal
     */
    public function recordWithdrawal(float $amount): void
    {
        // @phpstan-ignore-next-line
        $this->total_withdrawn = bcadd($this->total_withdrawn ?? 0, $amount, 2);
        // @phpstan-ignore-next-line
        $this->available = bcsub($this->available ?? 0, $amount, 2);
        $this->save();
    }

    /**
     * Record refund
     */
    public function recordRefund(float $amount): void
    {
        $this->refunded = bcadd($this->refunded ?? 0, $amount, 2); // @phpstan-ignore-line
        $this->pending = bcsub($this->pending ?? 0, $amount, 2); // @phpstan-ignore-line
        $this->save();
    }

    /**
     * Settle pending balance to available
     */
    public function settlePending(?float $amount = null): void
    {
        $toSettle = $amount ?? $this->pending;
        if ($toSettle > 0) {
            // @phpstan-ignore-next-line
            $this->pending = bcsub($this->pending ?? 0, $toSettle, 2);
            // @phpstan-ignore-next-line
            $this->available = bcadd($this->available ?? 0, $toSettle, 2);
            $this->save();
        }
    }

    /**
     * Get wallet summary for dashboard
     */
    public function getSummary(): array
    {
        return [
            'user_id' => $this->user_id,
            'wallet_type' => $this->wallet_type,
            'available' => (float)($this->available ?? 0),
            'pending' => (float)($this->pending ?? 0),
            'total_balance' => (float)bcadd($this->available ?? 0, $this->pending ?? 0, 2),
            'lifetime_earned' => (float)($this->lifetime_earned ?? 0),
            'total_withdrawn' => (float)($this->total_withdrawn ?? 0),
            'refunded' => (float)($this->refunded ?? 0),
            'net_earned' => (float)bcsub(
                bcsub($this->lifetime_earned ?? 0, $this->total_withdrawn ?? 0, 2),
                $this->refunded ?? 0,
                2
            ),
            'earnings_breakdown' => $this->getEarningsBreakdown(),
        ];
    }
}
