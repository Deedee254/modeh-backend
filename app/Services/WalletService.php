<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WalletService handles all wallet operations for quiz masters and creators.
 *
 * Comprehensive Wallet Architecture:
 * - Platform Wallet (user_id=0): Receives all incoming money, distributes to others
 * - Admin Wallet: Net earnings after all payouts  
 * - Quiz Master Wallet: Earnings from quizzes and affiliates
 * - Quizee Wallet: Earnings from affiliates, tournaments, battles, subscriptions
 *
 * Multi-Source Earnings:
 * 1. Quiz Completion: Quiz Master + Affiliate get shares, platform keeps remainder
 * 2. Tournament Winnings: Direct credit to quizee
 * 3. Battle Rewards: Direct credit to quizee  
 * 4. Subscription Revenue: Credited to platform wallet
 * 5. Affiliate Commissions: Direct credit to affiliate wallet
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
            ->where('status', Transaction::STATUS_COMPLETED)
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
            ->where('status', Transaction::STATUS_COMPLETED)
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
            ->where('status', Transaction::STATUS_COMPLETED)
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
        // Earned this calendar month (from confirmed transactions)
        $startOfMonth = now()->startOfMonth();
        $earnedThisMonth = (float) Transaction::where('quiz_master_id', $userId)
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $startOfMonth)
            ->sum('quiz_master_share');

        // Reconciliation: compare recorded lifetime_earned with computed total from transactions
        $difference = $totalEarnings - (float)$wallet->lifetime_earned;

        // Monthly breakdown (last 6 months)
        $monthly = $this->getMonthlyEarnings($userId, 6);
        $topQuizzes = $this->getTopQuizzes($userId, 10);

        return [
            'available' => (float)$wallet->available,
            'pending' => (float)$wallet->pending,
            'lifetime_earned' => (float)$wallet->lifetime_earned,
            'earned_this_month' => $earnedThisMonth,
            'monthly_breakdown' => $monthly,
            'top_quizzes' => $topQuizzes,
            'total_from_transactions' => $totalEarnings,
            'difference' => $difference, // positive means DB lifetime_earned is behind transaction totals
        ];
    }

    /**
     * Get monthly earnings for the past N months (including current month).
     * Returns array of ['month' => 'YYYY-MM', 'label' => 'Mar 2026', 'amount' => float]
     *
     * @param int $userId
     * @param int $months
     * @return array
     */
    public function getMonthlyEarnings(int $userId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $results = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = now()->subMonths($i)->startOfMonth();
            $end = now()->subMonths($i)->endOfMonth();

            $sum = (float) Transaction::where('quiz_master_id', $userId)
                ->where('status', 'confirmed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('quiz_master_share');

            $results[] = [
                'month' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'amount' => $sum,
            ];
        }

        return $results;
    }

    /**
     * Get top performing quizzes for a quiz-master by quiz_master_share sums.
     * Returns array of ['quiz_id' => int, 'title' => string, 'amount' => float]
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getTopQuizzes(int $userId, int $limit = 10): array
    {
        $rows = Transaction::where('quiz_master_id', $userId)
            ->where('status', 'confirmed')
            ->whereNotNull('quiz_id')
            ->selectRaw('quiz_id, SUM(quiz_master_share) as total')
            ->groupBy('quiz_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($rows as $r) {
            $quiz = \App\Models\Quiz::find($r->quiz_id);
            $results[] = [
                'quiz_id' => (int)$r->quiz_id,
                'title' => $quiz?->title ?? 'Unknown',
                'amount' => (float)$r->total,
            ];
        }

        return $results;
    }

    /**
     * MULTI-EARNING FLOW METHODS
     * ===========================
     */

    /**
     * Process quiz completion with full payout distribution.
     * Flow: Money in → Affiliate gets share → Quiz Master gets share → Platform keeps remainder
     *
     * @param int $quizMasterId Creator of the quiz
     * @param float $amount Total amount from quizee payment
     * @param string|null $referralCode Affiliate referral code (optional)
     * @param int|null $quizId Quiz ID reference
     * @param float $qmCommissionRate QM commission percentage (default 60%)
     * @return array Distribution summary
     */
    public static function processQuizPayout(
        int $quizMasterId,
        float $amount,
        ?string $referralCode = null,
        ?int $quizId = null,
        float $qmCommissionRate = 60
    ): array {
        return DB::transaction(function () use (
            $quizMasterId,
            $amount,
            $referralCode,
            $quizId,
            $qmCommissionRate
        ) {
            $distribution = [];
            $remaining = $amount;

            // 1. Get/Create platform wallet (user_id = 0) - receives all money
            $platformWallet = Wallet::firstOrCreate(
                ['user_id' => 0, 'type' => Wallet::TYPE_PLATFORM],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );

            $platformWallet->available = bcadd($platformWallet->available, $amount, 2);
            $platformWallet->lifetime_earned = bcadd($platformWallet->lifetime_earned, $amount, 2);
            $platformWallet->save();

            $distribution['platform_debit'] = [
                'amount' => (float)$amount,
                'type' => 'platform_debit',
                'description' => 'Quiz payment received',
            ];

            // 2. Pay affiliate first if referral code exists (deduct from platform share)
            $affiliateShare = 0;
            if ($referralCode) {
                $affiliate = \App\Models\Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    $affiliateShare = bcmul($amount, bcdiv($affiliate->commission_rate, 100, 4), 2);
                    $remaining = bcsub($remaining, $affiliateShare, 2);

                    $affiliateWallet = Wallet::firstOrCreate(
                        ['user_id' => $affiliate->user_id, 'type' => Wallet::TYPE_QUIZEE],
                        ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                    );

                    $affiliateWallet->recordEarning(
                        'affiliates',
                        $affiliateShare,
                        "Affiliate commission from quiz (code: {$referralCode})"
                    );

                    $distribution['affiliate_credit'] = [
                        'user_id' => $affiliate->user_id,
                        'amount' => (float)$affiliateShare,
                        'type' => 'affiliate_payout',
                        'description' => "Affiliate commission ({$affiliate->commission_rate}% of " . number_format($amount, 2) . ")",
                    ];

                    Log::info('Affiliate paid from quiz', [
                        'affiliate_id' => $affiliate->id,
                        'amount' => $affiliateShare,
                        'quiz_id' => $quizId,
                    ]);
                }
            }

            // 3. Pay quiz master from remaining (60% of what's left after affiliate)
            $qmShare = bcmul($remaining, bcdiv($qmCommissionRate, 100, 4), 2);
            $remaining = bcsub($remaining, $qmShare, 2);

            $qmWallet = Wallet::firstOrCreate(
                ['user_id' => $quizMasterId, 'type' => Wallet::TYPE_QUIZ_MASTER],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );

            $qmWallet->recordEarning(
                'quizzes',
                $qmShare,
                "Quiz earnings from quiz #$quizId"
            );

            $distribution['qm_credit'] = [
                'user_id' => $quizMasterId,
                'amount' => (float)$qmShare,
                'type' => 'quiz_master_payout',
                'description' => "Quiz master commission ({$qmCommissionRate}% of remaining " . number_format($qmShare + $remaining, 2) . ")",
            ];

            Log::info('Quiz master paid', [
                'quiz_master_id' => $quizMasterId,
                'amount' => $qmShare,
                'quiz_id' => $quizId,
            ]);

            // 4. Platform keeps remainder
            $platformShare = $remaining;
            $distribution['platform_credit'] = [
                'amount' => (float)$platformShare,
                'type' => 'platform_credit',
                'description' => 'Platform operating fund',
                'percentage' => round(($platformShare / $amount) * 100, 2),
            ];

            // 5. Create audit transaction
            $mainTx = Transaction::create([
                'quiz_master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $amount,
                'affiliate_share' => $affiliateShare,
                'quiz_master_share' => $qmShare,
                'platform_share' => $platformShare,
                'type' => Transaction::TYPE_PAYMENT,
                'status' => Transaction::STATUS_COMPLETED,
                'description' => "Quiz completion payout (Quiz #$quizId)",
                'balance_after' => $platformWallet->available,
                'meta' => [
                    'distribution_breakdown' => $distribution,
                    'referral_code' => $referralCode,
                    'qm_commission_rate' => $qmCommissionRate,
                ],
            ]);

            Log::info('Quiz payout processed', [
                'transaction_id' => $mainTx->id,
                'quiz_id' => $quizId,
                'total' => $amount,
                'affiliate_share' => $affiliateShare,
                'qm_share' => $qmShare,
                'platform_share' => $platformShare,
            ]);

            return [
                'success' => true,
                'transaction_id' => $mainTx->id,
                'distribution' => $distribution,
                'summary' => [
                    'total_in' => (float)$amount,
                    'affiliate_share' => (float)$affiliateShare,
                    'quiz_master_share' => (float)$qmShare,
                    'platform_share' => (float)$platformShare,
                ],
            ];
        });
    }

    /**
     * Record tournament winnings for quizee
     */
    public static function recordTournamentWinning(
        int $quizeeId,
        float $amount,
        ?int $tournamentId = null,
        string $description = ''
    ): Wallet {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $quizeeId, 'type' => Wallet::TYPE_QUIZEE],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );

        $wallet->recordEarning(
            'tournaments',
            $amount,
            $description ?: "Tournament winning (Tournament #$tournamentId)"
        );

        return $wallet;
    }

    /**
     * Record battle reward for quizee  
     */
    public static function recordBattleReward(
        int $quizeeId,
        float $amount,
        ?int $battleId = null,
        string $description = ''
    ): Wallet {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $quizeeId, 'type' => Wallet::TYPE_QUIZEE],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );

        $wallet->recordEarning(
            'battles',
            $amount,
            $description ?: "Battle reward (Battle #$battleId)"
        );

        return $wallet;
    }

    /**
     * Record subscription payment
     */
    public static function recordSubscriptionPayment(
        float $amount,
        string $planName,
        string $description = ''
    ): array {
        return DB::transaction(function () use ($amount, $planName, $description) {
            // Platform receives subscription payment
            $platformWallet = Wallet::firstOrCreate(
                ['user_id' => 0, 'type' => Wallet::TYPE_PLATFORM],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );

            $platformWallet->recordEarning(
                'subscriptions',
                $amount,
                $description ?: "Subscription payment for {$planName}"
            );

            return [
                'success' => true,
                'platform_credited' => (float)$amount,
                'plan' => $planName,
            ];
        });
    }

    /**
     * Get admin financial overview with all wallet summaries
     */
    public static function getAdminFinancialOverview(): array
    {
        $platformWallet = Wallet::where('type', Wallet::TYPE_PLATFORM)->first();
        $adminWallet = Wallet::where('type', Wallet::TYPE_ADMIN)->first();

        $paymentTxs = Transaction::where('type', Transaction::TYPE_PAYMENT)->sum('amount') ?? 0;
        $affiliatePayouts = Transaction::where('type', Transaction::TYPE_AFFILIATE_PAYOUT)
            ->sum('amount') ?? 0;
        $qmPayouts = Transaction::where('type', Transaction::TYPE_QUIZ_MASTER_PAYOUT)
            ->sum('amount') ?? 0;

        $last30Days = Transaction::where('created_at', '>=', now()->subDays(30))
            ->sum('amount') ?? 0;

        return [
            'platform_wallet' => $platformWallet?->getSummary() ?? [],
            'admin_wallet' => $adminWallet?->getSummary() ?? [],
            'revenue' => [
                'all_time_payments' => (float)$paymentTxs,
                'last_30_days' => (float)$last30Days,
            ],
            'payouts' => [
                'affiliates_total' => (float)$affiliatePayouts,
                'quiz_masters_total' => (float)$qmPayouts,
                'total_distributed' => (float)bcadd($affiliatePayouts, $qmPayouts, 2),
            ],
            'platform_profit' => (float)bcsub(
                $paymentTxs,
                bcadd($affiliatePayouts, $qmPayouts, 2),
                2
            ),
        ];
    }
}
