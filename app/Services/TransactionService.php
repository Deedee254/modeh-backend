<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\AffiliateReferral;
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
            $amount = (float)$paymentData['amount'];
            $quizMasterId = $paymentData['quiz_master_id'];
            $userId = $paymentData['user_id'] ?? null;
            $quizId = $paymentData['quiz_id'] ?? null;
            $referralCode = $paymentData['referral_code'] ?? null;
            $gateway = $paymentData['gateway'] ?? 'mpesa';
            $txId = $paymentData['tx_id'] ?? null;

            // Initialize payment tracking
            // The platform keeps what's left after affiliate and QM shares
            
            // Initial shares
            $affiliateShare = 0;
            $quizMasterShare = 0;
            $platformShare = $amount;
            $affiliateUser = null;

            // 2. STEP 1: Calculate and deduct affiliate commission from FULL AMOUNT
            if ($referralCode) {
                $affiliate = \App\Models\Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    $affiliateShare = bcmul($amount, $affiliate->commission_rate / 100, 2);
                    $platformShare = bcsub($platformShare, $affiliateShare, 2);
                    $affiliateUser = $affiliate->user_id;

                    // Credit affiliate wallet with pending balance
                    $affiliateWallet = Wallet::firstOrCreate(
                        ['user_id' => $affiliate->user_id],
                        ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
                    );
                    $affiliateWallet->pending = bcadd($affiliateWallet->pending, $affiliateShare, 2);
                    $affiliateWallet->lifetime_earned = bcadd($affiliateWallet->lifetime_earned, $affiliateShare, 2);
                    $affiliateWallet->save();

                    // Record affiliate referral
                    AffiliateReferral::create([
                        'affiliate_id' => $affiliate->id,
                        'user_id' => $userId,
                        'type' => 'quiz_purchase',
                        'earnings' => $affiliateShare,
                        'status' => 'pending',
                    ]);

                    // Log affiliate payout transaction
                    Transaction::create([
                        'tx_id' => $txId ? "{$txId}-affiliate" : null,
                        'user_id' => $affiliate->user_id,
                        'quiz-master_id' => $quizMasterId,
                        'quiz_id' => $quizId,
                        'amount' => $affiliateShare,
                        'affiliate_share' => $affiliateShare,
                        'gateway' => $gateway,
                        'type' => Transaction::TYPE_AFFILIATE_PAYOUT,
                        'status' => Transaction::STATUS_COMPLETED,
                        'description' => "Affiliate commission from quiz purchase (code: {$referralCode})",
                        'reference_id' => $txId,
                        'balance_after' => $affiliateWallet->pending,
                        'meta' => [
                            'referral_code' => $referralCode,
                            'commission_rate' => (float)$affiliate->commission_rate,
                            'source' => 'payment_distribution',
                        ],
                    ]);
                }
            }

            // 3. STEP 2: Calculate quiz master share from REMAINDER (after affiliate deduction)
            // Using configurable commission rate from payment settings (default 60% to quiz master, 40% to platform)
            $qmCommissionRate = (float)($paymentData['qm_commission_rate'] ?? 60);
            $quizMasterShare = bcmul($platformShare, $qmCommissionRate / 100, 2);
            $platformShare = bcsub($platformShare, $quizMasterShare, 2);

            // Credit quiz master wallet
            $qmWallet = Wallet::firstOrCreate(
                ['user_id' => $quizMasterId],
                ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
            );
            $qmWallet->pending = bcadd($qmWallet->pending, $quizMasterShare, 2);
            $qmWallet->lifetime_earned = bcadd($qmWallet->lifetime_earned, $quizMasterShare, 2);
            $qmWallet->save();

            // Log quiz master payout transaction
            Transaction::create([
                'tx_id' => $txId ? "{$txId}-qm" : null,
                'user_id' => $userId,
                'quiz-master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $quizMasterShare,
                'quiz-master_share' => $quizMasterShare,
                'gateway' => $gateway,
                'type' => Transaction::TYPE_QUIZ_MASTER_PAYOUT,
                'status' => Transaction::STATUS_COMPLETED,
                'description' => 'Quiz master commission from quiz purchase',
                'reference_id' => $txId,
                'balance_after' => $qmWallet->pending,
                'meta' => [
                    'commission_rate' => $qmCommissionRate,
                    'source' => 'payment_distribution',
                ],
            ]);

            // 4. Log platform credit (remainder)
            $mainTransaction = Transaction::create([
                'tx_id' => $txId,
                'user_id' => $userId,
                'quiz-master_id' => $quizMasterId,
                'quiz_id' => $quizId,
                'amount' => $amount,
                'affiliate_share' => $affiliateShare,
                'quiz-master_share' => $quizMasterShare,
                'platform_share' => $platformShare,
                'gateway' => $gateway,
                'type' => Transaction::TYPE_PAYMENT,
                'status' => Transaction::STATUS_COMPLETED,
                'description' => 'Quiz payment received',
                'reference_id' => $txId,
                'balance_after' => $platformShare,
                'meta' => [
                    'affiliate_share' => (float)$affiliateShare,
                    'qm_share' => (float)$quizMasterShare,
                    'platform_share' => (float)$platformShare,
                    'referral_code' => $referralCode,
                ],
            ]);

            Log::info('Payment processed successfully', [
                'transaction_id' => $mainTransaction->id,
                'amount' => $amount,
                'affiliate_share' => $affiliateShare,
                'qm_share' => $quizMasterShare,
                'platform_share' => $platformShare,
            ]);

            return $mainTransaction;
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
                Transaction::TYPE_QUIZ_MASTER_PAYOUT => $credit->quizMaster?->name ?? 'Quiz Master #' . $credit->quiz_master_id,
                default => 'Unknown'
            };

            $flow[] = [
                'order' => $order++,
                'type' => 'CREDIT',
                'category' => $credit->getTypeLabel(),
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
