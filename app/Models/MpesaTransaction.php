<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * MpesaTransaction Model
 * 
 * @property int $id
 * @property int $user_id
 * @property string $checkout_request_id
 * @property string $merchant_request_id
 * @property float $amount
 * @property string $phone
 * @property ?string $mpesa_receipt
 * @property string $status
 * @property ?int $result_code
 * @property ?string $result_desc
 * @property ?\DateTime $transaction_date
 * @property ?\DateTime $reconciled_at
 * @property ?array $raw_response
 * @property int $retry_count
 * @property ?\DateTime $last_retry_at
 * @property ?\DateTime $next_retry_at
 * @property ?string $billable_type
 * @property ?int $billable_id
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class MpesaTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'checkout_request_id',
        'merchant_request_id',
        'amount',
        'phone',
        'mpesa_receipt',
        'status',
        'result_code',
        'result_desc',
        'transaction_date',
        'reconciled_at',
        'raw_response',
        'retry_count',
        'last_retry_at',
        'next_retry_at',
        'billable_type',
        'billable_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'result_code' => 'integer',
        'raw_response' => 'array',
        'transaction_date' => 'datetime',
        'reconciled_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns this transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the billable entity (e.g., Subscription)
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to pending transactions ready for retry
     */
    public function scopePendingRetry($query)
    {
        return $query
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            });
    }

    /**
     * Mark as successfully reconciled
     */
    public function markSuccess(?string $receipt = null, ?string $resultDesc = null, $transactionDate = null): void
    {
        $parsedDate = self::parseTransactionDate($transactionDate);
        $this->update([
            'status' => 'success',
            'result_code' => 0,
            'result_desc' => $resultDesc ?? 'Payment received',
            'mpesa_receipt' => $receipt ?? $this->mpesa_receipt,
            'transaction_date' => $parsedDate ?? $this->transaction_date,
            'reconciled_at' => now(),
        ]);
    }

    /**
     * Mark as failed with reason
     */
    public function markFailed(?int $resultCode = null, ?string $resultDesc = null): void
    {
        $this->update([
            'status' => 'failed',
            'result_code' => $resultCode,
            'result_desc' => $resultDesc ?? 'Payment failed',
            'reconciled_at' => now(),
        ]);
    }

    /**
     * Schedule next retry with exponential backoff
     */
    public function scheduleRetry(): void
    {
        // Exponential backoff: 5s, 30s, 2min, 10min, then stop
        $backoff = [5, 30, 120, 600];
        $delaySeconds = $backoff[min($this->retry_count, count($backoff) - 1)] ?? 600;

        $this->update([
            'retry_count' => $this->retry_count + 1,
            'last_retry_at' => now(),
            'next_retry_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    /**
     * Parse a Daraja transaction date (YYYYMMDDHHMMSS) or generic date string.
     */
    public static function parseTransactionDate($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        if (preg_match('/^\d{14}$/', $str)) {
            try {
                return Carbon::createFromFormat('YmdHis', $str, config('app.timezone', 'UTC'));
            } catch (\Throwable $_) {
                return null;
            }
        }

        $ts = strtotime($str);
        if ($ts !== false) {
            return Carbon::createFromTimestamp($ts, config('app.timezone', 'UTC'));
        }

        return null;
    }
}
