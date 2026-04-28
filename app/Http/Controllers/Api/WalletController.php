<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Models\User;
use App\Models\Quiz;
use App\Services\TransactionService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // Reconcile wallet with calculated stats for quiz-masters
        // This ensures the wallet reflects actual earnings from completed transactions
        if ($user->role === 'quiz-master' && !empty($walletStats)) {
            $wallet->lifetime_earned = $walletStats['total_from_transactions'];
            $wallet->earned_this_month = $walletStats['earned_this_month'];
            $wallet->save();
        }

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
            
            // Safely get earnings breakdown
            $earningsBreakdown = [];
            try {
                if (method_exists($wallet, 'getEarningsBreakdown')) {
                    $earningsBreakdown = $wallet->getEarningsBreakdown();
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to get earnings breakdown', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'ok' => true,
                'wallet' => $wallet,
                'wallet_stats' => $walletStats,
                'pending_payments' => $pendingPaymentsSummary,
                'earnings_breakdown' => $earningsBreakdown,
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
            ->where('quiz_master_id', $user->id)
            ->whereIn('type', $visibleTypes)
            ->orderBy('created_at', 'desc');

        if ($request->filled('quiz_id')) $q->where('quiz_id', $request->quiz_id);
        if ($request->filled('from')) $q->where('created_at', '>=', $request->from);
        if ($request->filled('to')) $q->where('created_at', '<=', $request->to);
        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;
        $txs = $q->paginate($perPage);

        $items = $txs->items();
        $payoutRefs = [];
        foreach ($items as $item) {
            if (($item->type ?? null) === Transaction::TYPE_QUIZ_MASTER_PAYOUT) {
                $payoutRefs[] = $item->reference_id ?? $item->tx_id;
            }
        }
        $payoutRefs = array_unique(array_filter($payoutRefs));

        $sanitized = [];
        foreach ($items as $item) {
            $type = (string)($item->type ?? '');
            
            // If we have a dedicated quiz_master_payout for a payment, hide the payment row
            if ($type === Transaction::TYPE_PAYMENT) {
                $ref = $item->reference_id ?? $item->tx_id;
                if ($ref && in_array($ref, $payoutRefs)) {
                    continue;
                }
            }

            // Pull quiz-master share without exposing platform numbers.
            $qmShare = 0.0;
            try {
                $qmShare = (float)($item->{'quiz-master_share'} ?? 0);
            } catch (\Throwable $e) {
                $qmShare = 0.0;
            }

            $amount = (float)($item->amount ?? 0);
            $direction = 'credit';

            if ($type === Transaction::TYPE_WITHDRAWAL) {
                $direction = 'debit';
            } elseif ($type === Transaction::TYPE_PAYMENT) {
                // For payment rows (fallback), show only quiz-master share when present.
                if ($qmShare > 0) $amount = $qmShare;
            }

            $meta = $item->meta;
            if (is_array($meta)) {
                foreach ([
                    'platform_share', 'platformShare', 'platform', 'platform_fee',
                    'affiliate_share', 'affiliateShare', 'affiliate',
                    'shares', // may include platform share breakdown
                ] as $k) {
                    if (array_key_exists($k, $meta)) unset($meta[$k]);
                }
            }

            $sanitized[] = [
                'id' => $item->id,
                'created_at' => $item->created_at,
                'status' => $item->status,
                'type' => $type,
                'description' => $item->description,
                'reference_id' => $item->reference_id ?? $item->tx_id,
                'gateway' => $item->gateway,
                'quiz_id' => $item->quiz_id,
                'quiz' => $item->quiz ? [
                    'id' => $item->quiz->id,
                    'title' => $item->quiz->title,
                    'slug' => $item->quiz->slug,
                ] : null,
                'tx_id' => $item->tx_id,
                'amount' => (float)$amount,
                'direction' => $direction,
                'balance_after' => $item->balance_after !== null ? (float)$item->balance_after : null,
                'meta' => $meta,
            ];
        }

        $txs->setCollection(collect($sanitized));

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
                $wallet->setAttribute('available', (string)bcsub((string)$wallet->available, (string)$amount, 2));
                $wallet->save();

                // create withdrawal request
                $wr = WithdrawalRequest::create([
                    'quiz_master_id' => $user->id,
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
        } catch (\Throwable $e) { }

        Log::channel('payment')->info("Withdrawal request created: User {$user->id}, Amount KES {$amount}", [
            'user_id' => $user->id,
            'amount' => $amount,
            'method' => $wr->method ?? 'unknown',
            'status' => 'pending',
            'new_available' => (float)$wallet->available
        ]);

        return response()->json(['ok' => true, 'withdrawal' => $wr]);
    }

    public function myWithdrawals()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $list = WithdrawalRequest::where('quiz_master_id', $user->id)->orderBy('created_at', 'desc')->get();
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
        $transactions = Transaction::where('quiz_master_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'ok' => true,
            'wallet' => $wallet,
            'transactions' => $transactions
        ]);
    }



    // Admin: Finance dashboard metrics
    public function adminMetrics()
    {
        $user = Auth::user();
        if (!$user || !isset($user->is_admin) || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Platform wallet balance
        $platformUserId = User::where('role', 'admin')->orderBy('id')->first()?->id ?? 0;
        $platformWallet = Wallet::where('user_id', $platformUserId)->first();
        $platformBalance = $platformWallet ? (float)$platformWallet->available : 0;

        // Total revenue (all transactions)
        $totalRevenue = Transaction::sum('amount') ?? 0;

        // Last 30 days cash flow
        $thirtyDaysAgo = now()->subDays(30);
        $cashInLast30 = Transaction::where('created_at', '>=', $thirtyDaysAgo)
            ->where('amount', '>', 0)
            ->sum('amount') ?? 0;
        $cashOutLast30 = Transaction::where('created_at', '>=', $thirtyDaysAgo)
            ->where('amount', '<', 0)
            ->sum('amount') ?? 0;

        // Breakdown by item type
        $revenueBreakdown = Transaction::where('amount', '>', 0)
            ->whereNotNull('meta->item_type')
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(meta, "$.item_type")) as item_type, SUM(amount) as total')
            ->groupBy('item_type')
            ->get()
            ->pluck('total', 'item_type')
            ->toArray();

        // Fund allocation breakdown
        $qmAvailableTotal = Wallet::where('user_id', '!=', $platformUserId)
            ->where('type', Wallet::TYPE_QUIZ_MASTER)
            ->sum('available') ?? 0;
            
        $affiliateAvailableTotal = Wallet::where('type', Wallet::TYPE_QUIZEE)
            ->where('user_id', '!=', $platformUserId)
            ->sum('available') ?? 0;

        return response()->json([
            'ok' => true,
            'data' => [
                'platform_balance' => (float)$platformBalance,
                'total_revenue' => (float)$totalRevenue,
                'cash_in_last_30' => (float)$cashInLast30,
                'cash_out_last_30' => (float)$cashOutLast30,
                'net_flow_last_30' => (float)($cashInLast30 + $cashOutLast30),
                'revenue_breakdown' => [
                    'quizzes' => (float)($revenueBreakdown['quiz'] ?? 0),
                    'subscriptions' => (float)($revenueBreakdown['subscription'] ?? 0) + (float)($revenueBreakdown['package'] ?? 0),
                    'tournaments' => (float)($revenueBreakdown['tournament'] ?? 0),
                    'battles' => (float)($revenueBreakdown['battle'] ?? 0),
                    'other' => (float)max(0, $totalRevenue - (
                        (float)($revenueBreakdown['quiz'] ?? 0) + 
                        (float)($revenueBreakdown['subscription'] ?? 0) + 
                        (float)($revenueBreakdown['package'] ?? 0) + 
                        (float)($revenueBreakdown['tournament'] ?? 0) + 
                        (float)($revenueBreakdown['battle'] ?? 0)
                    ))
                ],
                'fund_allocation' => [
                    'platform_ops' => (float)$platformBalance,
                    'quiz_master_wallets' => (float)$qmAvailableTotal,
                    'affiliate_wallets' => (float)$affiliateAvailableTotal,
                ]
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

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;

        $transactions = $query->with(['user', 'quizMaster', 'quiz'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $transactions->getCollection()->transform(function ($tx) {
            $meta = is_array($tx->meta) ? $tx->meta : [];
            $resolvedType = $tx->type
                ?? ($meta['type'] ?? null)
                ?? (($tx->quiz_master_share ?? 0) > 0 || ($tx->platform_share ?? 0) > 0 ? Transaction::TYPE_PAYMENT : null)
                ?? 'unknown';

            $resolvedQuiz = $tx->quiz;
            if (!$resolvedQuiz && ($meta['item_type'] ?? null) === 'quiz' && !empty($meta['item_id'])) {
                $resolvedQuiz = Quiz::find($meta['item_id']);
            }

            $resolvedQuizMaster = $tx->quizMaster;
            if (!$resolvedQuizMaster && $resolvedQuiz?->user_id) {
                $resolvedQuizMaster = User::find($resolvedQuiz->user_id);
            }

            return [
                'id' => $tx->id,
                'tx_id' => $tx->tx_id,
                'type' => $resolvedType,
                'raw_type' => $tx->type,
                'amount' => (float) ($tx->amount ?? 0),
                'status' => $tx->status,
                'description' => $tx->description,
                'balance_after' => $tx->balance_after !== null ? (float) $tx->balance_after : null,
                'gateway' => $tx->gateway,
                'platform_share' => (float) ($tx->platform_share ?? 0),
                'quiz_master_share' => (float) ($tx->quiz_master_share ?? 0),
                'affiliate_share' => (float) ($tx->affiliate_share ?? 0),
                'meta' => $meta,
                'user' => $tx->user ? [
                    'id' => $tx->user->id,
                    'name' => $tx->user->name,
                    'email' => $tx->user->email,
                ] : null,
                'quiz' => $resolvedQuiz ? [
                    'id' => $resolvedQuiz->id,
                    'title' => $resolvedQuiz->title,
                    'slug' => $resolvedQuiz->slug,
                ] : null,
                'quiz_master' => $resolvedQuizMaster ? [
                    'id' => $resolvedQuizMaster->id,
                    'name' => $resolvedQuizMaster->name,
                    'email' => $resolvedQuizMaster->email,
                ] : null,
                'quiz_master_id' => $resolvedQuizMaster?->id ?? $tx->{'quiz_master_id'},
                'resolved' => [
                    'type' => $resolvedType,
                    'quiz_master_source' => $tx->quizMaster ? 'transaction' : ($resolvedQuizMaster ? 'quiz_owner' : null),
                    'quiz_source' => $tx->quiz ? 'transaction' : ($resolvedQuiz ? 'meta.item_id' : null),
                ],
                'created_at' => $tx->created_at,
            ];
        });

        return response()->json([
            'ok' => true,
            'transactions' => $transactions
        ]);
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
            $query = Transaction::query()
                ->where(function ($q) {
                    $q->where('type', Transaction::TYPE_PAYMENT)
                      ->orWhere(function ($nested) {
                          $nested->whereNull('type')
                              ->where(function ($shares) {
                                  $shares->where('platform_share', '>', 0)
                                      ->orWhere('quiz-master_share', '>', 0)
                                      ->orWhere('affiliate_share', '>', 0);
                              });
                      });
                });

            if ($request->filled('from')) {
                $query->where('created_at', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $query->where('created_at', '<=', $request->to);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

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

            $transactions->getCollection()->transform(function ($tx) {
                $meta = is_array($tx->meta) ? $tx->meta : [];
                $resolvedType = $tx->type
                    ?? ($meta['type'] ?? null)
                    ?? (($tx->quiz_master_share ?? 0) > 0 || ($tx->platform_share ?? 0) > 0 ? Transaction::TYPE_PAYMENT : null)
                    ?? 'unknown';

                $resolvedQuiz = $tx->quiz;
                if (!$resolvedQuiz && ($meta['item_type'] ?? null) === 'quiz' && !empty($meta['item_id'])) {
                    $resolvedQuiz = Quiz::find($meta['item_id']);
                }

                $resolvedQuizMaster = $tx->quizMaster;
                if (!$resolvedQuizMaster && $resolvedQuiz?->user_id) {
                    $resolvedQuizMaster = User::find($resolvedQuiz->user_id);
                }

                return [
                    'id' => $tx->id,
                    'tx_id' => $tx->tx_id,
                    'type' => $resolvedType,
                    'raw_type' => $tx->type,
                    'amount' => (float) $tx->amount,
                    'affiliate_share' => (float) ($tx->affiliate_share ?? 0),
                    'quiz_master_share' => (float) ($tx->quiz_master_share ?? 0),
                    'platform_share' => (float) ($tx->platform_share ?? 0),
                    'status' => $tx->status,
                    'gateway' => $tx->gateway,
                    'quiz' => [
                        'id' => $resolvedQuiz?->id,
                        'title' => $resolvedQuiz?->title,
                    ],
                    'quiz_master' => [
                        'id' => $resolvedQuizMaster?->id,
                        'name' => $resolvedQuizMaster?->name,
                        'email' => $resolvedQuizMaster?->email,
                    ],
                    'quiz_master_id' => $resolvedQuizMaster?->id ?? $tx->{'quiz_master_id'},
                    'user' => [
                        'id' => $tx->user?->id,
                        'name' => $tx->user?->name,
                        'email' => $tx->user?->email,
                    ],
                    'created_at' => $tx->created_at,
                    'meta' => $meta,
                    'resolved' => [
                        'type' => $resolvedType,
                        'quiz_master_source' => $tx->quizMaster ? 'transaction' : ($resolvedQuizMaster ? 'quiz_owner' : null),
                        'quiz_source' => $tx->quiz ? 'transaction' : ($resolvedQuiz ? 'meta.item_id' : null),
                    ],
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
     * Admin broadcasts a notification to a filtered set of users.
     * POST /api/admin/broadcast
     */
    public function adminBroadcast(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'targets' => 'required|array',
            'targets.type' => 'required|string|in:all,quizees,quizee-masters,taxonomy,quiz-attempts',
            'targets.level' => 'nullable',
            'targets.grade' => 'nullable',
            'targets.subject' => 'nullable',
            'targets.quiz_id' => 'nullable',
        ]);

        try {
            $recipientIds = $this->resolveBroadcastRecipientIds($validated['targets'], (int) $user->id);

            if (empty($recipientIds)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No recipients matched the selected criteria.',
                    'recipients_count' => 0,
                ], 422);
            }

            $now = now();
            $rows = [];
            foreach ($recipientIds as $recipientId) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'type' => 'App\Notifications\AdminBroadcast',
                    'notifiable_type' => 'App\Models\User',
                    'notifiable_id' => $recipientId,
                    'data' => json_encode([
                        'title' => $validated['title'],
                        'message' => $validated['message'],
                        'from_admin' => $user->name,
                        'targets' => $validated['targets'],
                    ]),
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('notifications')->insert($chunk);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Broadcast sent successfully',
                'recipients_count' => count($recipientIds),
            ]);
        } catch (\Throwable $e) {
            Log::error('Admin broadcast failed', [
                'admin_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to send broadcast',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveBroadcastRecipientIds(array $targets, int $senderId): array
    {
        $type = $targets['type'] ?? 'all';
        $query = User::query()->where('id', '!=', $senderId);

        if ($type === 'all') {
            $query->whereIn('role', ['quizee', 'quiz-master']);
        } elseif ($type === 'quizees') {
            $query->where('role', 'quizee');
        } elseif ($type === 'quizee-masters') {
            $query->where('role', 'quiz-master');
        } elseif ($type === 'quiz-attempts') {
            $quizId = $this->resolveQuizId($targets['quiz_id'] ?? null);
            if (!$quizId) {
                return [];
            }
            $query->whereHas('quizAttempts', function ($attempts) use ($quizId) {
                $attempts->where('quiz_id', $quizId);
            });
        } elseif ($type === 'taxonomy') {
            $levelIds = $this->resolveTaxonomyIds(\App\Models\Level::query(), $targets['level'] ?? null);
            $gradeIds = $this->resolveTaxonomyIds(\App\Models\Grade::query(), $targets['grade'] ?? null);
            $subjectIds = $this->resolveTaxonomyIds(\App\Models\Subject::query(), $targets['subject'] ?? null);

            if (empty($levelIds) && empty($gradeIds) && empty($subjectIds)) {
                return [];
            }

            $query->where(function ($userQuery) use ($levelIds, $gradeIds, $subjectIds) {
                $userQuery
                    ->where(function ($quizeeQuery) use ($levelIds, $gradeIds, $subjectIds) {
                        $quizeeQuery->where('role', 'quizee')
                            ->whereHas('quizeeProfile', function ($profileQuery) use ($levelIds, $gradeIds, $subjectIds) {
                                $this->applyTaxonomyFilters($profileQuery, $levelIds, $gradeIds, $subjectIds);
                            });
                    })
                    ->orWhere(function ($masterQuery) use ($levelIds, $gradeIds, $subjectIds) {
                        $masterQuery->where('role', 'quiz-master')
                            ->whereHas('quizMasterProfile', function ($profileQuery) use ($levelIds, $gradeIds, $subjectIds) {
                                $this->applyTaxonomyFilters($profileQuery, $levelIds, $gradeIds, $subjectIds);
                            });
                    });
            });
        }

        return $query->distinct()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function resolveQuizId($rawQuiz): ?int
    {
        if ($rawQuiz === null || $rawQuiz === '') {
            return null;
        }

        if (is_numeric($rawQuiz)) {
            return Quiz::whereKey((int) $rawQuiz)->value('id');
        }

        $value = trim((string) $rawQuiz);
        return Quiz::query()
            ->where('slug', $value)
            ->orWhere('title', 'like', '%' . $value . '%')
            ->value('id');
    }

    private function resolveTaxonomyIds($query, $rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }

        if (is_array($rawValue)) {
            $values = array_values(array_filter($rawValue, fn ($value) => $value !== null && $value !== ''));
        } else {
            $values = [trim((string) $rawValue)];
        }

        $ids = [];
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            $resolved = $query->getModel()::query()
                ->when(is_numeric($value), fn ($q) => $q->orWhere('id', (int) $value))
                ->when(method_exists($query->getModel(), 'getRouteKeyName') || \Schema::hasColumn($query->getModel()->getTable(), 'slug'), fn ($q) => $q->orWhere('slug', $value))
                ->orWhere('name', $value)
                ->pluck('id')
                ->all();

            if (empty($resolved) && preg_match('/(\d+)/', (string) $value, $matches)) {
                $resolved = array_merge($resolved, $query->getModel()::query()->where('id', (int) $matches[1])->pluck('id')->all());
            }

            $ids = array_merge($ids, $resolved);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function applyTaxonomyFilters($profileQuery, array $levelIds, array $gradeIds, array $subjectIds): void
    {
        if (!empty($levelIds)) {
            $profileQuery->whereIn('level_id', $levelIds);
        }

        if (!empty($gradeIds)) {
            $profileQuery->whereIn('grade_id', $gradeIds);
        }

        if (!empty($subjectIds)) {
            $profileQuery->where(function ($subjectQuery) use ($subjectIds) {
                foreach ($subjectIds as $subjectId) {
                    $subjectQuery->orWhereJsonContains('subjects', $subjectId);
                }
            });
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
            $recipient = User::findOrFail($recipientId);

            // Create notification entry for inbox message
            DB::table('notifications')->insert([
                'id' => Str::uuid(),
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
                    $result = app(TransactionService::class)->processPaidQuizPayment(
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
                    $pendingPayment = app(TransactionService::class)->createPendingPayment(
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




