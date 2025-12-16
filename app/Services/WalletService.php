<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletService handles all wallet operations for quiz masters and creators.
 *
 * Earning Flow:
 * 1. Transaction created when payment received (quiz, battle, subscription, tournament)
 * 2. Quiz master share calculated and stored in transactions.quiz_master_share
 * 3. creditWallet() called to add to wallet.pending
 * 4. Admin settles pending -> available via settlePending()
 * 5. User withdraws available balance
 *
 * Balance States:
 * - pending: Awaiting admin settlement (quiz/battle/subscription earnings)
 * - available: Ready to withdraw (after admin settlement)
 * - lifetime_earned: Total ever earned (never decreases, for analytics)
 */
class WalletService
{
    /**
     * Credit a user's wallet with the given amount.
     * Amount is added to 'pending' balance by default (awaiting settlement).
     *
     * @param int $userId
     * @param float $amount
     * @param string|null $description Optional description for logging
     * @return Wallet|null
     */
    public function credit(int $userId, float $amount, ?string $description = null): ?Wallet
    {
        if ($amount <= 0) {
            Log::warning("Wallet credit amount must be positive", ['user_id' => $userId, 'amount' => $amount]);
            return null;
        }

        try {
            return DB::transaction(function () use ($userId, $amount, $description) {
                $wallet = Wallet::where('user_id', $userId)
                    ->lockForUpdate()
                    ->firstOrCreate(
                        ['user_id' => $userId],
                        ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                    );

                // Add to pending balance (awaiting settlement)
                $wallet->pending = bcadd((string)$wallet->pending, (string)$amount, 2);
                // Track total earned over time
                $wallet->lifetime_earned = bcadd((string)$wallet->lifetime_earned, (string)$amount, 2);
                $wallet->save();

                // Log the transaction
                Log::info("Wallet credited", [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'new_pending' => $wallet->pending,
                    'description' => $description,
                ]);

                // Broadcast update to user
                event(new \App\Events\WalletUpdated($wallet->toArray(), $userId));

                return $wallet;
            });
        } catch (\Throwable $e) {
            Log::error("Wallet credit failed", [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Settle (move) pending balance to available for withdrawal.
     * Only admins should call this after verifying transactions.
     *
     * @param int $userId
     * @param float|null $amount If null, settle entire pending balance
     * @return Wallet|null
     */
    public function settle(int $userId, ?float $amount = null): ?Wallet
    {
        try {
            return DB::transaction(function () use ($userId, $amount) {
                $wallet = Wallet::where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    Log::warning("Wallet not found for settlement", ['user_id' => $userId]);
                    return null;
                }

                // Default: settle entire pending balance
                $settleAmount = $amount ?? $wallet->pending;

                if ($settleAmount <= 0) {
                    Log::warning("Settlement amount must be positive", ['user_id' => $userId, 'amount' => $settleAmount]);
                    return null;
                }

                if ($settleAmount > $wallet->pending) {
                    Log::warning("Settlement amount exceeds pending balance", [
                        'user_id' => $userId,
                        'requested' => $settleAmount,
                        'pending' => $wallet->pending,
                    ]);
                    return null;
                }

                // Move from pending to available
                $wallet->pending = bcsub((string)$wallet->pending, (string)$settleAmount, 2);
                $wallet->available = bcadd((string)$wallet->available, (string)$settleAmount, 2);
                $wallet->save();

                Log::info("Wallet settled", [
                    'user_id' => $userId,
                    'amount' => $settleAmount,
                    'new_available' => $wallet->available,
                    'new_pending' => $wallet->pending,
                ]);

                // Broadcast update
                event(new \App\Events\WalletUpdated($wallet->toArray(), $userId));

                return $wallet;
            });
        } catch (\Throwable $e) {
            Log::error("Wallet settlement failed", [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Debit from available balance (for withdrawals).
     *
     * @param int $userId
     * @param float $amount
     * @return Wallet|null
     */
    public function debit(int $userId, float $amount): ?Wallet
    {
        if ($amount <= 0) {
            Log::warning("Debit amount must be positive", ['user_id' => $userId, 'amount' => $amount]);
            return null;
        }

        try {
            return DB::transaction(function () use ($userId, $amount) {
                $wallet = Wallet::where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (!$wallet) {
                    Log::warning("Wallet not found for debit", ['user_id' => $userId]);
                    return null;
                }

                if ($amount > $wallet->available) {
                    Log::warning("Insufficient available balance for debit", [
                        'user_id' => $userId,
                        'requested' => $amount,
                        'available' => $wallet->available,
                    ]);
                    return null;
                }

                $wallet->available = bcsub((string)$wallet->available, (string)$amount, 2);
                $wallet->save();

                Log::info("Wallet debited", [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'new_available' => $wallet->available,
                ]);

                event(new \App\Events\WalletUpdated($wallet->toArray(), $userId));

                return $wallet;
            });
        } catch (\Throwable $e) {
            Log::error("Wallet debit failed", [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get or create a user's wallet.
     *
     * @param int $userId
     * @return Wallet
     */
    public function getOrCreate(int $userId): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );
    }

    /**
     * Get a user's wallet (read-only).
     *
     * @param int $userId
     * @return Wallet|null
     */
    public function get(int $userId): ?Wallet
    {
        return Wallet::where('user_id', $userId)->first();
    }

    /**
     * Get total earnings from all sources for a user.
     * Includes all transaction quiz_master_shares.
     *
     * @param int $userId
     * @return float
     */
    public function getTotalEarnings(int $userId): float
    {
        $total = Transaction::where('quiz_master_id', $userId)
            ->where('status', 'confirmed')
            ->sum('quiz_master_share');

        return (float)($total ?? 0);
    }

    /**
     * Get earnings breakdown by type (quiz, battle, subscription, etc).
     *
     * @param int $userId
     * @return array
     */
    public function getEarningsBreakdown(int $userId): array
    {
        $breakdown = Transaction::where('quiz_master_id', $userId)
            ->where('status', 'confirmed')
            ->selectRaw('
                CASE 
                    WHEN meta->"$.item_type" = "quiz" THEN "Quiz"
                    WHEN meta->"$.item_type" = "battle" THEN "Battle"
                    WHEN meta->"$.item_type" = "tournament" THEN "Tournament"
                    ELSE "Subscription"
                END as type,
                COUNT(*) as count,
                SUM(quiz_master_share) as total
            ')
            ->groupBy('type')
            ->get()
            ->toArray();

        return $breakdown;
    }

    /**
     * Get recent transactions for a user.
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getRecentTransactions(int $userId, int $limit = 20): array
    {
        return Transaction::where('quiz_master_id', $userId)
            ->where('status', 'confirmed')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Validate that wallet can debit the given amount.
     *
     * @param int $userId
     * @param float $amount
     * @return bool
     */
    public function canDebit(int $userId, float $amount): bool
    {
        $wallet = $this->get($userId);
        if (!$wallet) {
            return false;
        }
        return $amount > 0 && $amount <= (float)$wallet->available;
    }

    /**
     * Get wallet statistics for analytics.
     *
     * @param int $userId
     * @return array
     */
    public function getStats(int $userId): array
    {
        $wallet = $this->get($userId);
        if (!$wallet) {
            $wallet = $this->getOrCreate($userId);
        }

        $totalEarnings = $this->getTotalEarnings($userId);
        $settled = (float)$wallet->available + (float)bcadd((string)$wallet->pending, (string)$wallet->available, 2) - (float)$wallet->available;

        return [
            'available' => (float)$wallet->available,
            'pending' => (float)$wallet->pending,
            'lifetime_earned' => (float)$wallet->lifetime_earned,
            'total_from_transactions' => $totalEarnings,
            'difference' => $totalEarnings - (float)$wallet->lifetime_earned, // Should be 0 or very small
        ];
    }
}
