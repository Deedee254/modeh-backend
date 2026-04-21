<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\AffiliateReferral;
use App\Models\PaymentSetting;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    /**
     * Process a payment with proper distribution:
     * 1. Money comes in (debit to platform)
     * 2. Affiliate gets their share (credit to affiliate wallet)
     * 3. Quiz Master gets their share (credit to quiz master wallet)
     * 4. Platform keeps remainder
     */
    public static function processPayment(array $paymentData): Transaction
    {
        return DB::transaction(function () use ($paymentData) {
            $amount = (float) ($paymentData['amount'] ?? 0);
            $quizMasterId = $paymentData['quiz_master_id'] ?? null;
            $userId = $paymentData['user_id'] ?? null;
            $quizId = $paymentData['quiz_id'] ?? null;
            $referralCode = $paymentData['referral_code'] ?? null;
            $gateway = $paymentData['gateway'] ?? 'mpesa';
            $txId = $paymentData['tx_id'] ?? null;
            $itemType = $paymentData['item_type'] ?? ($quizId ? 'quiz' : null);
            $itemId = $paymentData['item_id'] ?? $quizId;
            $purchaseId = $paymentData['purchase_id'] ?? null;
            $attemptId = $paymentData['attempt_id'] ?? null;

            if (!$quizMasterId && $quizId) {
                $quizMasterId = Quiz::query()->whereKey($quizId)->value('user_id')
                    ?? Quiz::query()->whereKey($quizId)->value('created_by');
            }

            $affiliateShare = 0;
            $quizMasterShare = 0;
            $platformShare = $amount;
            $platformWallet = Wallet::firstOrCreate(
                ['user_id' => 0, 'type' => Wallet::TYPE_PLATFORM],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );
            if (!$platformWallet->type) {
                $platformWallet->type = Wallet::TYPE_PLATFORM;
            }

            if ($referralCode) {
                $affiliate = \App\Models\Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    $affiliateShare = bcmul((string) $amount, (string) ($affiliate->commission_rate / 100), 2);
                    $platformShare = bcsub((string) $platformShare, (string) $affiliateShare, 2);

                    $affiliateWallet = Wallet::firstOrCreate(
                        ['user_id' => $affiliate->user_id],
                        ['type' => Wallet::TYPE_QUIZEE, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                    );
                    if (!$affiliateWallet->type) {
                        $affiliateWallet->type = Wallet::TYPE_QUIZEE;
                    }
                    $affiliateWallet->pending = bcadd((string) ($affiliateWallet->pending ?? 0), (string) $affiliateShare, 2);
                    $affiliateWallet->lifetime_earned = bcadd((string) ($affiliateWallet->lifetime_earned ?? 0), (string) $affiliateShare, 2);
                    $affiliateWallet->earned_from_affiliates = bcadd((string) ($affiliateWallet->earned_from_affiliates ?? 0), (string) $affiliateShare, 2);
                    $affiliateWallet->save();

                    if ($userId) {
                        AffiliateReferral::create([
                            'affiliate_id' => $affiliate->id,
                            'user_id' => $userId,
                            'type' => 'quiz_purchase',
                            'earnings' => $affiliateShare,
                            'status' => 'pending',
                        ]);
                    }

                    Transaction::create([
                        'tx_id' => $txId ? "{$txId}-affiliate" : null,
                        'user_id' => $affiliate->user_id,
                        'quiz_master_id' => $quizMasterId,
                        'quiz_id' => $quizId,
                        'amount' => $affiliateShare,
                        'affiliate_share' => $affiliateShare,
                        'gateway' => $gateway,
                        'type' => Transaction::TYPE_AFFILIATE_PAYOUT,
                        'status' => Transaction::STATUS_COMPLETED,
                        'description' => "Affiliate commission from payment (code: {$referralCode})",
                        'reference_id' => $txId,
                        'balance_after' => $affiliateWallet->pending,
                        'meta' => [
                            'referral_code' => $referralCode,
                            'commission_rate' => (float) $affiliate->commission_rate,
                            'source' => 'payment_distribution',
                            'item_type' => $itemType,
                            'item_id' => $itemId,
                            'purchase_id' => $purchaseId,
                            'attempt_id' => $attemptId,
                        ],
                    ]);
                }
            }

            $platformPct = PaymentSetting::platformRevenueSharePercent();
            $qmCommissionRate = PaymentSetting::quizMasterRevenueSharePercent();
            $qmWallet = null;

            if ($quizMasterId) {
                $quizMasterShare = bcmul((string) $platformShare, (string) ($qmCommissionRate / 100), 2);
                $platformShare = bcsub((string) $platformShare, (string) $quizMasterShare, 2);

                $qmWallet = Wallet::firstOrCreate(
                    ['user_id' => $quizMasterId],
                    ['type' => Wallet::TYPE_QUIZ_MASTER, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                );
                if (!$qmWallet->type) {
                    $qmWallet->type = Wallet::TYPE_QUIZ_MASTER;
                }

                $qmWallet->pending = bcadd((string) ($qmWallet->pending ?? 0), (string) $quizMasterShare, 2);
                $qmWallet->lifetime_earned = bcadd((string) ($qmWallet->lifetime_earned ?? 0), (string) $quizMasterShare, 2);
                $qmWallet->earned_this_month = bcadd((string) ($qmWallet->earned_this_month ?? 0), (string) $quizMasterShare, 2);

                $earningField = match ($itemType) {
                    'tournament' => 'earned_from_tournaments',
                    'subscription', 'package' => 'earned_from_subscriptions',
                    'battle' => 'earned_from_battles',
                    default => 'earned_from_quizzes',
                };
                $qmWallet->{$earningField} = bcadd((string) ($qmWallet->{$earningField} ?? 0), (string) $quizMasterShare, 2);
                $qmWallet->save();

                Transaction::create([
                    'tx_id' => $txId ? "{$txId}-qm" : null,
                    'user_id' => $userId,
                    'quiz_master_id' => $quizMasterId,
                    'quiz_id' => $quizId,
                    'amount' => $quizMasterShare,
                    'quiz-master_share' => $quizMasterShare,
                    'gateway' => $gateway,
                    'type' => Transaction::TYPE_QUIZ_MASTER_PAYOUT,
                    'status' => Transaction::STATUS_COMPLETED,
                    'description' => 'Quiz master commission from payment',
                    'reference_id' => $txId,
                    'balance_after' => $qmWallet->pending,
                    'meta' => [
                        'commission_rate' => $qmCommissionRate,
                        'platform_revenue_share_pct' => $platformPct,
                        'source' => 'payment_distribution',
                        'item_type' => $itemType,
                        'item_id' => $itemId,
                        'purchase_id' => $purchaseId,
                        'attempt_id' => $attemptId,
                    ],
                ]);
            }

            $platformWallet->available = bcadd((string) ($platformWallet->available ?? 0), (string) $platformShare, 2);
            $platformWallet->lifetime_earned = bcadd((string) ($platformWallet->lifetime_earned ?? 0), (string) $platformShare, 2);
            $platformWallet->save();

            $mainTransaction = Transaction::create([
                'tx_id' => $txId,
                'user_id' => $userId,
                'quiz_master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $amount,
                'affiliate_share' => $affiliateShare,
                'quiz-master_share' => $quizMasterShare,
                'platform_share' => $platformShare,
                'gateway' => $gateway,
                'type' => Transaction::TYPE_PAYMENT,
                'status' => Transaction::STATUS_COMPLETED,
                'description' => $paymentData['description'] ?? 'Payment received',
                'reference_id' => $txId,
                'balance_after' => (float) ($platformWallet->available ?? 0),
                'meta' => [
                    'affiliate_share' => (float) $affiliateShare,
                    'qm_share' => (float) $quizMasterShare,
                    'platform_share' => (float) $platformShare,
                    'platform_revenue_share_pct' => $platformPct,
                    'referral_code' => $referralCode,
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'purchase_id' => $purchaseId,
                    'attempt_id' => $attemptId,
                    'quiz_master_id' => $quizMasterId,
                ],
            ]);

            Log::channel('payment')->info("Payment processed [{$txId}]: Gross KES {$amount}", [
                'tx_id' => $txId,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'distribution' => [
                    'affiliate' => (float)$affiliateShare,
                    'quiz_master' => (float)$quizMasterShare,
                    'platform' => (float)$platformShare,
                ],
                'wallets' => [
                    'platform_new_balance' => (float)$platformWallet->available,
                    'qm_id' => $quizMasterId,
                    'qm_new_pending' => $qmWallet ? (float)$qmWallet->pending : null,
                ]
            ]);

            return $mainTransaction;
        });
    }

    /**
     * Process a PAID quiz payment - credits quiz master immediately
     */
    public static function processPaidQuizPayment(
        int $quizMasterId,
        int $quizeeId,
        int $quizId,
        int $quizAttemptId,
        float $amount,
        ?string $referralCode = null,
    ): array {
        return DB::transaction(function () use (
            $quizMasterId,
            $quizeeId,
            $quizId,
            $quizAttemptId,
            $amount,
            $referralCode,
        ) {
            $qmCommissionRate = PaymentSetting::quizMasterRevenueSharePercent();
            $distribution = [];
            $remaining = $amount;

            // Affiliate commission
            $affiliateShare = 0;
            if ($referralCode) {
                $affiliate = \App\Models\Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    $affiliateShare = bcmul($amount, $affiliate->commission_rate / 100, 2);
                    $remaining = bcsub($remaining, $affiliateShare, 2);

                    $affWallet = Wallet::firstOrCreate(['user_id' => $affiliate->user_id]);
                    $affWallet->pending = bcadd($affWallet->pending ?? 0, $affiliateShare, 2);
                    $affWallet->lifetime_earned = bcadd($affWallet->lifetime_earned ?? 0, $affiliateShare, 2);
                    $affWallet->earned_from_affiliates = bcadd($affWallet->earned_from_affiliates ?? 0, $affiliateShare, 2);
                    $affWallet->save();
                }
            }

            // Quiz Master Share - Goes to PENDING (wait for settlement)
            $qmShare = bcmul($remaining, $qmCommissionRate / 100, 2);
            $platformShare = bcsub($remaining, $qmShare, 2);

            $qmWallet = Wallet::firstOrCreate(['user_id' => $quizMasterId], ['type' => Wallet::TYPE_QUIZ_MASTER]);
            $qmWallet->pending = bcadd($qmWallet->pending ?? 0, $qmShare, 2);
            $qmWallet->earned_this_month = bcadd($qmWallet->earned_this_month ?? 0, $qmShare, 2);
            $qmWallet->lifetime_earned = bcadd($qmWallet->lifetime_earned ?? 0, $qmShare, 2);
            $qmWallet->earned_from_quizzes = bcadd($qmWallet->earned_from_quizzes ?? 0, $qmShare, 2);
            $qmWallet->save();

            // Platform Share - Credited to platform wallet immediately (user_id = 0)
            $platformWallet = Wallet::firstOrCreate(
                ['user_id' => 0, 'type' => Wallet::TYPE_PLATFORM],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );
            $platformWallet->available = bcadd($platformWallet->available ?? 0, $platformShare, 2);
            $platformWallet->lifetime_earned = bcadd($platformWallet->lifetime_earned ?? 0, $platformShare, 2);
            $platformWallet->save();

            // Log transaction
            Transaction::create([
                'user_id' => $quizeeId,
                'quiz_id' => $quizId,
                'quiz_attempt_id' => $quizAttemptId,
                'amount' => $amount,
                'quiz-master_share' => $qmShare,
                'platform_share' => $platformShare,
                'affiliate_share' => $affiliateShare,
                'type' => 'quiz_completion_payment',
                'payment_status' => 'paid',
                'status' => 'completed',
                'description' => "Quiz payment received from quizee",
            ]);

            event(new \App\Events\WalletUpdated($qmWallet->toArray(), $quizMasterId));

            Log::channel('payment')->info("Paid quiz payment processed: Gross KES {$amount}", [
                'quizee_id' => $quizeeId,
                'quiz_id' => $quizId,
                'distribution' => [
                    'affiliate' => (float)$affiliateShare,
                    'quiz_master' => (float)$qmShare,
                    'platform' => (float)$platformShare,
                ],
                'qm_new_pending' => (float)$qmWallet->pending,
                'platform_new_available' => (float)$platformWallet->available,
            ]);

            return [
                'success' => true,
                'quiz_master_earned' => (float)$qmShare,
                'platform_earned' => (float)$platformShare,
                'affiliate_earned' => (float)$affiliateShare,
            ];
        });
    }

    /**
     * Create pending payment record (unpaid quiz)
     */
    public static function createPendingPayment(
        int $quizMasterId,
        int $quizeeId,
        int $quizId,
        int $quizAttemptId,
        float $amount,
        int $paymentDaysUntilDue = 7
    ): \App\Models\PendingQuizPayment {
        return DB::transaction(function () use (
            $quizMasterId,
            $quizeeId,
            $quizId,
            $quizAttemptId,
            $amount,
            $paymentDaysUntilDue
        ) {
            $pending = \App\Models\PendingQuizPayment::create([
                'quiz_master_id' => $quizMasterId,
                'quizee_id' => $quizeeId,
                'quiz_id' => $quizId,
                'quiz_attempt_id' => $quizAttemptId,
                'amount' => $amount,
                'status' => 'pending',
                'attempt_at' => now(),
                'payment_due_at' => now()->addDays($paymentDaysUntilDue),
                'recovery_status' => 'active',
            ]);

            // Log as pending payment transaction
            Transaction::create([
                'user_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'quiz_attempt_id' => $quizAttemptId,
                'pending_payment_id' => $pending->id,
                'amount' => $amount,
                'type' => 'pending_quiz_payment',
                'payment_status' => 'pending_payment',
                'status' => 'pending',
                'description' => "Pending payment from quizee for quiz",
            ]);

            return $pending;
        });
    }

    /**
     * Get transaction flow for a specific payment (shows debit and all credits)
     */
    public static function getPaymentFlow($mainTransactionId): array
    {
        $mainTx = Transaction::find($mainTransactionId);
        if (!$mainTx) {
            return [];
        }

        $referenceId = $mainTx->tx_id;
        $flow = [
            [
                'order' => 1,
                'type' => 'DEBIT',
                'category' => 'Payment Received',
                'description' => 'Money received from quizee',
                'amount' => $mainTx->amount,
                'recipient' => 'Platform',
                'status' => $mainTx->status,
                'timestamp' => $mainTx->created_at,
            ]
        ];

        // Get all related credit transactions
        $credits = Transaction::where('reference_id', $referenceId)
            ->whereIn('type', [
                Transaction::TYPE_AFFILIATE_PAYOUT,
                Transaction::TYPE_QUIZ_MASTER_PAYOUT,
            ])
            ->orderBy('created_at')
            ->get();

        $order = 2;
        foreach ($credits as $credit) {
            $recipient = match($credit->type) {
                Transaction::TYPE_AFFILIATE_PAYOUT => 'Affiliate (' . ($credit->meta['referral_code'] ?? 'N/A') . ')',
                Transaction::TYPE_QUIZ_MASTER_PAYOUT => $credit->quizMaster?->name ?? 'Quiz Master #' . $credit->{'quiz_master_id'},
                default => 'Unknown'
            };

            $typeLabel = match($credit->type) {
                Transaction::TYPE_AFFILIATE_PAYOUT => 'Affiliate Payout',
                Transaction::TYPE_QUIZ_MASTER_PAYOUT => 'Quiz Master Payout',
                default => ucfirst(str_replace('_', ' ', $credit->type ?? 'transaction'))
            };

            $flow[] = [
                'order' => $order++,
                'type' => 'CREDIT',
                'category' => $typeLabel,
                'description' => $credit->description,
                'amount' => $credit->amount,
                'recipient' => $recipient,
                'status' => $credit->status,
                'timestamp' => $credit->created_at,
            ];
        }

        return $flow;
    }

    /**
     * Get platform transaction summary for admin
     */
    public static function getPlatformSummary(): array
    {
        $platformWallet = Wallet::where('user_id', 0)->first();
        
        $allTime = Transaction::where('type', Transaction::TYPE_PAYMENT)->sum('amount') ?? 0;
        $last30Days = Transaction::where('type', Transaction::TYPE_PAYMENT)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount') ?? 0;
        $last7Days = Transaction::where('type', Transaction::TYPE_PAYMENT)
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('amount') ?? 0;

        $affiliatePayouts = Transaction::where('type', Transaction::TYPE_AFFILIATE_PAYOUT)
            ->sum('amount') ?? 0;
        $qmPayouts = Transaction::where('type', Transaction::TYPE_QUIZ_MASTER_PAYOUT)
            ->sum('amount') ?? 0;

        return [
            'platform_balance' => [
                'available' => (float)($platformWallet?->available ?? 0),
                'pending' => (float)($platformWallet?->pending ?? 0),
                'total' => (float)(bcadd($platformWallet?->available ?? 0, $platformWallet?->pending ?? 0, 2)),
            ],
            'revenue' => [
                'all_time' => (float)$allTime,
                'last_30_days' => (float)$last30Days,
                'last_7_days' => (float)$last7Days,
            ],
            'payouts' => [
                'affiliates_total' => (float)$affiliatePayouts,
                'quiz_masters_total' => (float)$qmPayouts,
                'total_paid_out' => (float)bcadd($affiliatePayouts, $qmPayouts, 2),
            ],
            'pending' => [
                'affiliate_settlements' => Transaction::where('type', Transaction::TYPE_AFFILIATE_PAYOUT)
                    ->where('status', Transaction::STATUS_PENDING)
                    ->sum('amount') ?? 0,
                'qm_settlements' => Transaction::where('type', Transaction::TYPE_QUIZ_MASTER_PAYOUT)
                    ->where('status', Transaction::STATUS_PENDING)
                    ->sum('amount') ?? 0,
            ]
        ];
    }
}




