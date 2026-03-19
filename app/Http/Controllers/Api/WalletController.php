<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Services\TransactionService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    public function mine()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        /** @var \App\Models\User $user */
        
        $walletType = $user->role === 'quizee'
            ? Wallet::TYPE_QUIZEE
            : ($user->role === 'quiz-master' ? Wallet::TYPE_QUIZ_MASTER : null);

        // Initialize wallet with new balance states
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id], 
            [
                'type' => $walletType,
                'available' => 0, 
                'pending' => 0,
                'withdrawn_pending' => 0, 
                'settled' => 0,
                'earned_this_month' => 0,
                'lifetime_earned' => 0,
                'earned_from_quizzes' => 0,
                'earned_from_affiliates' => 0,
                'earned_from_tournaments' => 0,
                'earned_from_battles' => 0,
                'earned_from_subscriptions' => 0,
            ]
        );

        if ($walletType && $wallet->type !== $walletType) {
            $wallet->type = $walletType;
            $wallet->save();
        }
        
        // Check and send due reminders (API-driven, 24hr logic)
        if ($user->role === 'quiz-master') {
            try {
                app(\App\Services\ReminderService::class)->checkAndSendDueReminders();
            } catch (\Throwable $e) {
                // Log but don't fail the response
                Log::warning('Failed to check reminders', ['error' => $e->getMessage()]);
            }
        }
        
        // Gather wallet stats (reconciliation helpers)
        $walletStats = app(WalletService::class)->getStats($user->id);

        // Include pending payments summary for quizee
        if ($user->role === 'quizee') {
            $pendingPaymentsSummary = \App\Models\PendingQuizPayment::where('quizee_id', $user->id)
                ->where('status', '!=', 'paid')
                ->select(['id', 'quiz_id', 'quiz_master_id', 'amount', 'status', 'payment_due_at', 'created_at'])
                ->with(['quiz:id,title,slug', 'quizMaster:id,name,email'])
                ->get();

            $affiliate = $user->affiliate()->first();
            $activeReferrals = $affiliate ? $affiliate->referrals()->where('status', 'active')->count() : 0;
            $totalReferrals = $affiliate ? $affiliate->referrals()->count() : 0;
            $conversionRate = $totalReferrals > 0 ? ($activeReferrals / $totalReferrals) * 100 : 0;
            
            return response()->json([
                'ok' => true,
                'wallet' => $wallet,
                'wallet_stats' => $walletStats,
                'pending_payments' => $pendingPaymentsSummary,
                'earnings_breakdown' => $wallet->getEarningsBreakdown(),
                'affiliate_stats' => [
                    'has_affiliate_account' => (bool) $affiliate,
                    'referral_code' => $affiliate?->referral_code,
                    'commission_rate' => $affiliate ? (float) $affiliate->commission_rate : 0,
                    'total_earned' => $affiliate ? (float) ($affiliate->total_earnings ?? 0) : 0,
                    'pending_payouts' => $affiliate ? (float) ($affiliate->pending_payouts ?? 0) : 0,
                    'active_referrals' => $activeReferrals,
                    'total_referrals' => $totalReferrals,
                    'conversion_rate' => round($conversionRate, 2),
                ],
            ]);
        }
        
        return response()->json(['ok' => true, 'wallet' => $wallet, 'wallet_stats' => $walletStats]);
    }

    public function transactions(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);

        // Quiz-master wallet view should only expose quiz-master amounts.
        // Do not return platform_share/affiliate_share to quiz-masters.
        $visibleTypes = [
            Transaction::TYPE_QUIZ_MASTER_PAYOUT,
            Transaction::TYPE_WITHDRAWAL,
            Transaction::TYPE_SETTLEMENT,
            Transaction::TYPE_REFUND,
            // Include payment as a fallback for older records that may not have
            // a corresponding quiz_master_payout entry; we will sanitize/dedupe below.
            Transaction::TYPE_PAYMENT,
        ];

        $q = Transaction::query()
            ->with(['quiz:id,title,slug'])
            ->where('quiz-master_id', $user->id)
            ->whereIn('type', $visibleTypes)
            ->orderBy('created_at', 'desc');

        if ($request->filled('quiz_id')) $q->where('quiz_id', $request->quiz_id);
        if ($request->filled('from')) $q->where('created_at', '>=', $request->from);
        if ($request->filled('to')) $q->where('created_at', '<=', $request->to);
        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;
        $txs = $q->paginate($perPage);

        $collection = $txs->getCollection();

        // If we have a dedicated quiz_master_payout for a payment, hide the payment row
        // (quiz masters should see their earnings, not the full payment breakdown).
        $payoutRefs = $collection
            ->filter(fn ($t) => ($t->type ?? null) === Transaction::TYPE_QUIZ_MASTER_PAYOUT)
            ->map(fn ($t) => $t->reference_id ?? $t->tx_id)
            ->filter()
            ->unique();

        $sanitized = $collection
            ->reject(function ($t) use ($payoutRefs) {
                if (($t->type ?? null) !== Transaction::TYPE_PAYMENT) return false;
                $ref = $t->reference_id ?? $t->tx_id;
                if (!$ref) return false;
                return $payoutRefs->contains($ref);
            })
            ->map(function ($t) {
                $type = (string)($t->type ?? '');

                // Pull quiz-master share without exposing platform numbers.
                $qmShare = 0.0;
                try {
                    $qmShare = (float)($t->{'quiz-master_share'} ?? 0);
                } catch (\Throwable $e) {
                    $qmShare = 0.0;
                }

                $amount = (float)($t->amount ?? 0);
                $direction = 'credit';

                if ($type === Transaction::TYPE_WITHDRAWAL) {
                    $direction = 'debit';
                } elseif ($type === Transaction::TYPE_PAYMENT) {
                    // For payment rows (fallback), show only quiz-master share when present.
                    if ($qmShare > 0) $amount = $qmShare;
                }

                $meta = $t->meta;
                if (is_array($meta)) {
                    foreach ([
                        'platform_share', 'platformShare', 'platform', 'platform_fee',
                        'affiliate_share', 'affiliateShare', 'affiliate',
                        'shares', // may include platform share breakdown
                    ] as $k) {
                        if (array_key_exists($k, $meta)) unset($meta[$k]);
                    }
                }

                return [
                    'id' => $t->id,
                    'created_at' => $t->created_at,
                    'status' => $t->status,
                    'type' => $type,
                    'description' => $t->description,
                    'reference_id' => $t->reference_id ?? $t->tx_id,
                    'gateway' => $t->gateway,
                    'quiz_id' => $t->quiz_id,
                    'quiz' => $t->quiz ? [
                        'id' => $t->quiz->id,
                        'title' => $t->quiz->title,
                        'slug' => $t->quiz->slug,
                    ] : null,
                    'tx_id' => $t->tx_id,
                    'amount' => (float)$amount,
                    'direction' => $direction,
                    'balance_after' => $t->balance_after !== null ? (float)$t->balance_after : null,
                    'meta' => $meta,
                ];
            });

        $txs->setCollection($sanitized);

        return response()->json(['ok' => true, 'transactions' => $txs]);
    }

    public function requestWithdrawal(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $amount = (float)$request->input('amount', 0);
        if ($amount <= 0) return response()->json(['ok' => false, 'message' => 'Invalid amount'], 400);
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
        if ($amount > $wallet->available) return response()->json(['ok' => false, 'message' => 'Insufficient balance'], 400);

        // Perform debit and withdrawal creation atomically in a DB transaction
        $wr = null;
        try {
            DB::transaction(function () use (&$wr, $wallet, $amount, $request, $user) {
                // debit available
                $wallet->available = bcsub($wallet->available, $amount, 2);
                $wallet->save();

                // create withdrawal request
                $wr = WithdrawalRequest::create([
                    'quiz-master_id' => $user->id,
                    'amount' => $amount,
                    'method' => $request->input('method', 'mpesa'),
                    'status' => 'pending',
                    'meta' => $request->input('meta', []),
                ]);
            });
        } catch (\Throwable $e) {
            // If the transaction failed, roll back and return an error
            return response()->json(['ok' => false, 'message' => 'Failed to create withdrawal request'], 500);
        }

        // Broadcast new withdrawal request to quiz-master (and admins if needed) after commit
        try {
            if ($wr) {
                event(new \App\Events\WithdrawalRequestUpdated($wr->toArray(), $user->id));
            }
        } catch (\Throwable $e) {
            // ignore broadcast errors
        }

        return response()->json(['ok' => true, 'withdrawal' => $wr]);
    }

    public function myWithdrawals()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $list = WithdrawalRequest::where('quiz-master_id', $user->id)->orderBy('created_at', 'desc')->get();
        return response()->json(['ok' => true, 'withdrawals' => $list]);
    }

    public function rewardsMy()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        
        // Get user's wallet
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]
        );
        
        // Get transactions (rewards earned)
        $transactions = Transaction::where('quiz-master_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'ok' => true,
            'wallet' => $wallet,
            'transactions' => $transactions
        ]);
    }

    // Admin: settle pending funds into available for a specific quiz-master
    public function settlePending(Request $request, $quizMasterId)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        if (!isset($user->is_admin) || !$user->is_admin) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $amount = $request->input('amount', null); // optional amount to settle; if null settle full pending

        $wallet = null;
        try {
            DB::transaction(function () use (&$wallet, $quizMasterId, $amount) {
                // lock wallet row
                $w = Wallet::where('user_id', $quizMasterId)->lockForUpdate()->first();
                if (!$w) {
                    $w = Wallet::create(['user_id' => $quizMasterId, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                }

                $pending = (float)$w->pending;
                $toSettle = $amount !== null ? (float)$amount : $pending;
                if ($toSettle <= 0) {
                    // nothing to do
                    $wallet = $w;
                    return;
                }
                if ($toSettle > $pending) {
                    // cap at pending
                    $toSettle = $pending;
                }

                // move from pending -> available
                $w->pending = bcsub($w->pending, $toSettle, 2);
                $w->available = bcadd($w->available, $toSettle, 2);
                $w->save();

                $wallet = $w;
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to settle pending funds'], 500);
        }

        // broadcast wallet update
        try {
            if ($wallet) {
                event(new \App\Events\WalletUpdated($wallet->toArray(), $quizMasterId));
            }
        } catch (\Throwable $_) { }

        return response()->json(['ok' => true, 'wallet' => $wallet]);
    }

    // Admin: Finance dashboard metrics
    public function adminMetrics()
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Platform wallet balance
        $platformWallet = Wallet::where('user_id', 0)->first();
        $platformBalance = $platformWallet ? (float)$platformWallet->available : 0;

        // Total revenue (all transactions)
        $totalRevenue = Transaction::sum('amount') ?? 0;

        // Pending settlements for quiz masters (pending balance)
        $pendingSettlements = Wallet::where('user_id', '!=', 0)
            ->sum('pending') ?? 0;

        // Affiliate payouts due (pending and active affiliate referrals)
        $affiliatePayoutsDue = \App\Models\AffiliateReferral::where('status', 'pending')
            ->sum('earnings') ?? 0;

        // Last 30 days cash flow
        $thirtyDaysAgo = now()->subDays(30);
        $cashInLast30 = Transaction::where('created_at', '>=', $thirtyDaysAgo)
            ->where('amount', '>', 0)
            ->sum('amount') ?? 0;
        $cashOutLast30 = Transaction::where('created_at', '>=', $thirtyDaysAgo)
            ->where('amount', '<', 0)
            ->sum('amount') ?? 0;

        return response()->json([
            'ok' => true,
            'data' => [
                'platform_balance' => (float)$platformBalance,
                'total_revenue' => (float)$totalRevenue,
                'pending_settlements' => (float)$pendingSettlements,
                'affiliate_payouts_due' => (float)$affiliatePayoutsDue,
                'cash_in_last_30' => (float)$cashInLast30,
                'cash_out_last_30' => (float)$cashOutLast30,
                'net_flow_last_30' => (float)($cashInLast30 + $cashOutLast30),
                'revenue_breakdown' => [
                    'quizzes' => (float)($totalRevenue * 0.70),
                    'subscriptions' => (float)($totalRevenue * 0.20),
                    'affiliates' => (float)($totalRevenue * 0.10),
                ],
                'fund_allocation' => [
                    'platform_ops' => (float)($platformBalance * 0.35),
                    'quiz_master_wallets' => (float)($platformBalance * 0.40),
                    'reserved' => (float)($platformBalance * 0.25),
                ],
            ]
        ]);
    }

    // Admin: Transaction history
    public function adminTransactions(Request $request)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = Transaction::query();

        // Filter by type if provided
        if ($request->filled('type')) {
            $query->where('status', $request->type);
        }

        // Filter by date range if provided
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Pagination
        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;

        $transactions = $query->with(['user', 'quizMaster', 'quiz'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'ok' => true,
            'transactions' => $transactions
        ]);
    }

    // Admin: Pending settlements for quiz masters
    public function pendingSettlements(Request $request)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = Wallet::query()
            ->where('pending', '>', 0)
            ->where('user_id', '!=', 0);

        // Optional: filter by user name if search provided
        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;

        $settlements = $query->with('user')
            ->orderBy('pending', 'desc')
            ->paginate($perPage);

        return response()->json([
            'ok' => true,
            'settlements' => $settlements
        ]);
    }

    // Admin: Settle ALL pending funds at once
    public function settleAllPending(Request $request)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            DB::transaction(function () {
                // Get all wallets with pending > 0
                $wallets = Wallet::where('pending', '>', 0)->lockForUpdate()->get();
                
                foreach ($wallets as $wallet) {
                    if ($wallet->pending > 0) {
                        $pending = (float)$wallet->pending;
                        
                        // Move from pending -> available
                        $wallet->pending = 0;
                        $wallet->available = bcadd($wallet->available, $pending, 2);
                        $wallet->save();

                        // Create transaction record
                        Transaction::create([
                            'user_id' => $wallet->user_id,
                            'quiz-master_id' => $wallet->user_id,
                            'amount' => $pending,
                            'status' => 'settlement',
                            'meta' => [
                                'type' => 'admin_bulk_settlement',
                                'settled_at' => now(),
                            ]
                        ]);

                        // Broadcast wallet update
                        try {
                            event(new \App\Events\WalletUpdated($wallet->toArray(), $wallet->user_id));
                        } catch (\Throwable $_) { }
                    }
                }
            });

            return response()->json(['ok' => true, 'message' => 'All pending settlements completed']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to settle pending funds'], 500);
        }
    }

    // Admin: Settle funds for a specific user
    public function settleSingleUser(Request $request, $userId)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $amount = $request->input('amount', null);

        $wallet = null;
        try {
            DB::transaction(function () use (&$wallet, $userId, $amount) {
                // lock wallet row
                $w = Wallet::where('user_id', $userId)->lockForUpdate()->first();
                if (!$w) {
                    $w = Wallet::create(['user_id' => $userId, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                }

                $pending = (float)$w->pending;
                $toSettle = $amount !== null ? (float)$amount : $pending;
                
                if ($toSettle <= 0) {
                    $wallet = $w;
                    return;
                }
                if ($toSettle > $pending) {
                    $toSettle = $pending;
                }

                // move from pending -> available
                $w->pending = bcsub($w->pending, $toSettle, 2);
                $w->available = bcadd($w->available, $toSettle, 2);
                $w->save();

                // Create transaction record for audit trail
                Transaction::create([
                    'user_id' => $userId,
                    'quiz-master_id' => $userId,
                    'amount' => $toSettle,
                    'status' => 'settlement',
                    'meta' => [
                        'type' => 'admin_settlement',
                        'settled_at' => now(),
                    ]
                ]);

                $wallet = $w;
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Failed to settle funds'], 500);
        }

        // broadcast wallet update
        try {
            if ($wallet) {
                event(new \App\Events\WalletUpdated($wallet->toArray(), $userId));
            }
        } catch (\Throwable $_) { }

        return response()->json(['ok' => true, 'wallet' => $wallet]);
    }

    // Admin: Get transaction flow details (debit + all credits for a payment)
    public function transactionFlow($transactionId)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $mainTx = Transaction::find($transactionId);
            if (!$mainTx) {
                return response()->json(['ok' => false, 'message' => 'Transaction not found'], 404);
            }

            $flow = TransactionService::getPaymentFlow($transactionId);
            
            return response()->json([
                'ok' => true,
                'data' => [
                    'transaction_id' => $mainTx->id,
                    'tx_id' => $mainTx->tx_id,
                    'total_amount' => (float)$mainTx->amount,
                    'initiated_at' => $mainTx->created_at,
                    'flow' => $flow,
                    'summary' => [
                        'total_in' => (float)$mainTx->amount,
                        'affiliate_share' => (float)($mainTx->affiliate_share ?? 0),
                        'quiz_master_share' => (float)($mainTx->quiz_master_share ?? 0),
                        'platform_share' => (float)($mainTx->platform_share ?? 0),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // Admin: Get enhanced transaction history with flow details
    public function transactionHistory(Request $request)
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $query = Transaction::where('type', Transaction::TYPE_PAYMENT);

            // Date filters
            if ($request->filled('from')) {
                $query->where('created_at', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $query->where('created_at', '<=', $request->to);
            }

            // Status filter
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Search by tx_id or quiz name
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('tx_id', 'like', "%{$search}%")
                      ->orWhereHas('quiz', function ($qq) use ($search) {
                          $qq->where('title', 'like', "%{$search}%");
                      });
                });
            }

            $perPage = (int)$request->input('per_page', 20);
            $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 20;

            $transactions = $query->with(['user', 'quizMaster', 'quiz'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Add flow summary to each transaction
            $transactions->getCollection()->transform(function ($tx) {
                return [
                    'id' => $tx->id,
                    'tx_id' => $tx->tx_id,
                    'amount' => (float)$tx->amount,
                    'affiliate_share' => (float)($tx->affiliate_share ?? 0),
                    'quiz_master_share' => (float)($tx->quiz_master_share ?? 0),
                    'platform_share' => (float)($tx->platform_share ?? 0),
                    'status' => $tx->status,
                    'gateway' => $tx->gateway,
                    'quiz' => [
                        'id' => $tx->quiz?->id,
                        'title' => $tx->quiz?->title,
                    ],
                    'quiz_master' => [
                        'id' => $tx->quizMaster?->id,
                        'name' => $tx->quizMaster?->name,
                        'email' => $tx->quizMaster?->email,
                    ],
                    'user' => [
                        'id' => $tx->user?->id,
                        'name' => $tx->user?->name,
                        'email' => $tx->user?->email,
                    ],
                    'created_at' => $tx->created_at,
                    'meta' => $tx->meta,
                ];
            });

            return response()->json([
                'ok' => true,
                'data' => $transactions
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // Admin: Get platform financial summary
    public function platformSummary()
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $summary = TransactionService::getPlatformSummary();
            
            return response()->json([
                'ok' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get quiz master's pending payments (unpaid quizzes from quizees)
     * GET /api/my/unpaid-quizzes
     */
    public function myUnpaidQuizzes(Request $request)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'quiz-master') {
            return response()->json(['ok' => false, 'message' => 'Only quiz masters can view unpaid quizzes'], 403);
        }

        $query = \App\Models\PendingQuizPayment::where('quiz_master_id', $user->id)
            ->where('status', '!=', 'paid');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment_due_at (overdue only)
        if ($request->boolean('overdue')) {
            $query->where('payment_due_at', '<', now());
        }

        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 20;

        $payments = $query->with(['quizee:id,name,email', 'quiz:id,title,slug', 'quizAttempt:id,score,max_score'])
            ->orderBy('payment_due_at', 'asc')
            ->paginate($perPage);

        return response()->json([
            'ok' => true,
            'unpaid_quizzes' => $payments
        ]);
    }

    /**
     * Get admin view of all pending payments (for settlement/recovery)
     * GET /api/admin/pending-payments
     */
    public function adminPendingPayments(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = \App\Models\PendingQuizPayment::query();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment_due_at (overdue only)
        if ($request->boolean('overdue')) {
            $query->where('payment_due_at', '<', now());
        }

        // Filter by quiz master
        if ($request->filled('quiz_master_id')) {
            $query->where('quiz_master_id', $request->quiz_master_id);
        }

        // Filter by quizee
        if ($request->filled('quizee_id')) {
            $query->where('quizee_id', $request->quizee_id);
        }

        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 20;

        $payments = $query->with([
            'quizee:id,name,email',
            'quizMaster:id,name,email',
            'quiz:id,title,slug',
            'quizAttempt:id,score,max_score'
        ])
        ->orderBy('payment_due_at', 'asc')
        ->paginate($perPage);

        // Add summary stats
        $stats = [
            'total_pending' => (float) \App\Models\PendingQuizPayment::where('status', 'pending')->sum('amount'),
            'total_overdue' => (float) \App\Models\PendingQuizPayment::where('status', 'overdue')
                ->orWhere(function ($q) {
                    $q->where('status', 'pending')->where('payment_due_at', '<', now());
                })
                ->sum('amount'),
            'count_pending' => \App\Models\PendingQuizPayment::where('status', 'pending')->count(),
            'count_overdue' => \App\Models\PendingQuizPayment::where('status', 'overdue')
                ->orWhere(function ($q) {
                    $q->where('status', 'pending')->where('payment_due_at', '<', now());
                })
                ->count(),
        ];

        return response()->json([
            'ok' => true,
            'pending_payments' => $payments,
            'stats' => $stats
        ]);
    }

    /**
     * Quiz master sends reminder to quizee about pending payment
     * POST /api/pending-payments/{id}/send-reminder
     */
    public function sendPendingPaymentReminder(Request $request, $pendingPaymentId)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $payment = \App\Models\PendingQuizPayment::findOrFail($pendingPaymentId);

        // Only quiz master or admin can send reminders
        if ($user->id !== $payment->quiz_master_id && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        try {
            // Send custom reminder message from quiz master
            $message = $request->input('message', null);
            
            app(\App\Services\ReminderService::class)->sendQuizMasterReminder(
                $payment,
                $message
            );

            return response()->json([
                'ok' => true,
                'message' => 'Reminder sent to quizee'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to send reminder',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin sends message to user via inbox/chat
     * POST /api/admin/chat/send
     */
    public function adminSendChatMessage(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'sometimes|in:low,medium,high',
        ]);

        try {
            $recipientId = $request->input('recipient_id');
            $recipient = \App\Models\User::findOrFail($recipientId);

            // Create notification entry for inbox message
            DB::table('notifications')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'type' => 'App\Notifications\AdminMessage',
                'notifiable_type' => 'App\Models\User',
                'notifiable_id' => $recipientId,
                'data' => json_encode([
                    'subject' => $request->input('subject'),
                    'message' => $request->input('message'),
                    'priority' => $request->input('priority', 'medium'),
                    'from_admin' => $user->name,
                ]),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Message sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process payment and handle auto-settlement for earned funds
     * POST /api/checkout/process-payment
     */
    public function processPayment(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $request->validate([
            'quiz_id' => 'required|exists:quizzes,id',
            'quiz_attempt_id' => 'required|exists:quiz_attempts,id',
            'quiz_master_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_status' => 'required|in:paid,pending',
        ]);

        try {
            return DB::transaction(function () use ($request, $user) {
                $quizMasterId = $request->input('quiz_master_id');
                $amount = (float) $request->input('amount');
                $paymentStatus = $request->input('payment_status');
                $quizId = $request->input('quiz_id');
                $quizAttemptId = $request->input('quiz_attempt_id');

                // Auto-settle: if paid, create transaction and credit available balance immediately
                if ($paymentStatus === 'paid') {
                    // Create transaction
                    $result = app(\App\Services\TransactionService::class)->processPaidQuizPayment(
                        quizMasterId: $quizMasterId,
                        quizeeId: $user->id,
                        quizId: $quizId,
                        quizAttemptId: $quizAttemptId,
                        amount: $amount
                    );

                    return response()->json([
                        'ok' => true,
                        'message' => 'Payment processed successfully',
                        'result' => $result,
                        'status' => 'completed'
                    ]);
                } else {
                    // Payment pending: create pending payment record for recovery
                    $pendingPayment = app(\App\Services\TransactionService::class)->createPendingPayment(
                        quizMasterId: $quizMasterId,
                        quizeeId: $user->id,
                        quizId: $quizId,
                        quizAttemptId: $quizAttemptId,
                        amount: $amount
                    );

                    return response()->json([
                        'ok' => true,
                        'message' => 'Payment pending - will be reminded',
                        'pending_payment' => $pendingPayment,
                        'status' => 'pending'
                    ]);
                }
            });
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to process payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
