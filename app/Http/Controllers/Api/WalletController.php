<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function mine()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id], ['available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
        return response()->json(['ok' => true, 'wallet' => $wallet]);
    }

    public function transactions(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $q = Transaction::query()->where('quiz-master_id', $user->id)->orderBy('created_at', 'desc');
        if ($request->filled('quiz_id')) $q->where('quiz_id', $request->quiz_id);
        if ($request->filled('from')) $q->where('created_at', '>=', $request->from);
        if ($request->filled('to')) $q->where('created_at', '<=', $request->to);
        $perPage = (int)$request->input('per_page', 20);
        $perPage = $perPage > 0 ? $perPage : 20;
        $txs = $q->paginate($perPage);
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
            'metrics' => [
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
}
