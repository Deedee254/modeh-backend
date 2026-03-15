<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tx_id',
        'user_id',
        'quiz-master_id',
        'quiz_id',
        'amount',
        'quiz-master_share',
        'platform_share',
        'affiliate_share',
        'gateway',
        'meta',
        'status',
        'type',
        'description',
        'reference_id',
        'balance_after',
    ];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
        'quiz-master_share' => 'decimal:2',
        'platform_share' => 'decimal:2',
        'affiliate_share' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * Transaction type constants
     */
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_AFFILIATE_PAYOUT = 'affiliate_payout';
    public const TYPE_QUIZ_MASTER_PAYOUT = 'quiz_master_payout';
    public const TYPE_PLATFORM_CREDIT = 'platform_credit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_SETTLEMENT = 'settlement';
    public const TYPE_REFUND = 'refund';

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function quizMaster()
    {
        return $this->belongsTo(User::class, 'quiz-master_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Get human-readable type label
     */
    public function getTypeLabel(): string
    {
        return match($this->type) {
            self::TYPE_PAYMENT => 'Quiz Payment',
            self::TYPE_AFFILIATE_PAYOUT => 'Affiliate Payout',
            self::TYPE_QUIZ_MASTER_PAYOUT => 'Quiz Master Payout',
            self::TYPE_PLATFORM_CREDIT => 'Platform Credit',
            self::TYPE_WITHDRAWAL => 'Withdrawal',
            self::TYPE_SETTLEMENT => 'Settlement',
            self::TYPE_REFUND => 'Refund',
            default => ucfirst(str_replace('_', ' ', $this->type ?? 'transaction'))
        };
    }

    /**
     * Check if transaction increases balance
     */
    public function isCredit(): bool
    {
        return in_array($this->type, [
            self::TYPE_AFFILIATE_PAYOUT,
            self::TYPE_QUIZ_MASTER_PAYOUT,
            self::TYPE_PLATFORM_CREDIT,
        ]);
    }

    /**
     * Check if transaction decreases balance
     */
    public function isDebit(): bool
    {
        return in_array($this->type, [
            self::TYPE_PAYMENT,
            self::TYPE_WITHDRAWAL,
        ]);
    }
}
