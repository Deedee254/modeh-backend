<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Models\PaymentSetting;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * Get admin dashboard metrics
     */
    public function metrics()
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Get date range (last 30 days)
        $thirtyDaysAgo = now()->subDays(30);
        $today = now();

        // Total revenue from all transactions
        $totalRevenue = (float) Transaction::where('status', Transaction::STATUS_COMPLETED)->sum('amount');

        // Sum platform and QM shares
        $platformShare = (float) Transaction::where('status', Transaction::STATUS_COMPLETED)->sum('platform_share');
        $quizMasterShare = (float) Transaction::where('status', Transaction::STATUS_COMPLETED)->sum('quiz-master_share');

        // Count users by role
        $totalUsers = User::count();
        $newUsersThisMonth = User::where('created_at', '>=', $thirtyDaysAgo)->count();

        $quizMasters = User::where('role', 'quiz-master')->count();
        $newQuizMasters = User::where('role', 'quiz-master')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $quizees = User::where('role', 'quizee')->count();
        $newQuizees = User::where('role', 'quizee')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        // Pending withdrawals
        $pendingWithdrawals = (float) DB::table('withdrawal_requests')
            ->where('status', 'pending')
            ->sum('amount');

        $pendingWithdrawalCount = DB::table('withdrawal_requests')
            ->where('status', 'pending')
            ->count();

        $revenueShare = (float) PaymentSetting::platformRevenueSharePercent();

        return response()->json([
            'ok' => true,
            'metrics' => [
                'totalRevenue' => $totalRevenue,
                'platformShare' => $platformShare,
                'quizMasterShare' => $quizMasterShare,
                'totalUsers' => $totalUsers,
                'newUsersThisMonth' => $newUsersThisMonth,
                'quizMasters' => $quizMasters,
                'newQuizMasters' => $newQuizMasters,
                'quizees' => $quizees,
                'newQuizees' => $newQuizees,
                'pendingWithdrawals' => $pendingWithdrawals,
                'pendingWithdrawalCount' => $pendingWithdrawalCount,
            ],
            'revenueShare' => $revenueShare,
        ]);
    }

    /**
     * Get admin transactions list with filters
     */
    public function transactions(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = Transaction::query()->with([
            'user:id,name,email,phone',
            'quizMaster:id,name,email,phone',
        ]);

        // Apply filters
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from') . ' 00:00:00');
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to') . ' 23:59:59');
        }

        if ($request->filled('type')) {
            $type = $request->input('type');
            $query->whereJsonContains('meta->item_type', $type);
        }
        
        if ($request->filled('tx_type')) {
            $query->where('type', $request->input('tx_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Get total count before pagination
        $total = $query->count();

        // Get transactions with pagination
        $perPage = (int) $request->input('limit', 50);
        $page = (int) $request->input('page', 1);

        $transactions = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($tx) {
                $payer = $tx->user ? [
                    'id' => $tx->user->id,
                    'name' => $tx->user->name,
                    'email' => $tx->user->email,
                    'phone' => $tx->user->phone,
                ] : null;

                $quizMaster = $tx->quizMaster ? [
                    'id' => $tx->quizMaster->id,
                    'name' => $tx->quizMaster->name,
                    'email' => $tx->quizMaster->email,
                    'phone' => $tx->quizMaster->phone,
                ] : null;

                return [
                    'id' => $tx->id,
                    'tx_id' => $tx->tx_id,
                    'user_id' => $tx->user_id,
                    'payer' => $payer,
                    'quiz_master_id' => $tx->{'quiz_master_id'},
                    'quiz_master' => $quizMaster,
                    'quiz_id' => $tx->quiz_id,
                    'type' => $tx->type,
                    'amount' => (float) $tx->amount,
                    'platform_share' => (float) $tx->platform_share,
                    'quiz-master_share' => (float) $tx->{'quiz-master_share'},
                    'gateway' => $tx->gateway,
                    'status' => $tx->status,
                    'meta' => $tx->meta,
                    'created_at' => $tx->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
            'transactions' => $transactions,
            'total' => $total,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    /**
     * Get admin users list with filters
     */
    public function users(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%$search%")
                    ->orWhere('name', 'like', "%$search%");
            });
        }

        $total = $query->count();
        $perPage = (int) $request->input('limit', 50);
        $page = (int) $request->input('page', 1);

        $users = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar_url' => $user->avatar_url,
                    'created_at' => $user->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
            'users' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    /**
     * Get admin quiz-masters list with stats
     */
    public function quizMasters(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $stats = Transaction::query()
            ->selectRaw("`quiz_master_id` as quiz_master_id")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN `quiz-master_share` ELSE 0 END) as total_earnings")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as transaction_count")
            ->groupBy(DB::raw('`quiz_master_id`'));

        $query = User::query()
            ->where('role', 'quiz-master')
            ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
            ->leftJoinSub($stats, 'tx_stats', function ($join) {
                $join->on('tx_stats.quiz_master_id', '=', 'users.id');
            })
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.avatar_url',
                'users.created_at',
                'wallets.available as wallet_available',
                'wallets.withdrawn_pending as wallet_withdrawn_pending',
                'wallets.lifetime_earned as wallet_lifetime_earned',
                'tx_stats.total_earnings as total_earnings',
                'tx_stats.transaction_count as transaction_count',
            ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%$search%")
                    ->orWhere('name', 'like', "%$search%");
            });
        }

        $total = $query->count();
        $perPage = (int) $request->input('limit', 50);
        $page = (int) $request->input('page', 1);

        $quizMasters = $query
            ->orderBy('users.created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'wallet' => [
                        'available' => (float) ($user->wallet_available ?? 0),
                        'withdrawn_pending' => (float) ($user->wallet_withdrawn_pending ?? 0),
                        'lifetime_earned' => (float) ($user->wallet_lifetime_earned ?? 0),
                    ],
                    'total_earnings' => (float) ($user->total_earnings ?? 0),
                    'transaction_count' => (int) ($user->transaction_count ?? 0),
                    'created_at' => $user->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
            // For frontend consistency: return in `users` like other list endpoints
            'users' => $quizMasters,
            // Backwards compatible alias
            'quiz_masters' => $quizMasters,
            'total' => $total,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    /**
     * Get admin withdrawals list
     */
    public function withdrawals(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = DB::table('withdrawal_requests')
            ->join('users', 'withdrawal_requests.quiz_master_id', '=', 'users.id')
            ->select([
                'withdrawal_requests.*',
                'users.name',
                'users.email',
            ]);

        if ($request->filled('status')) {
            $query->where('withdrawal_requests.status', $request->input('status'));
        }

        $total = $query->count();
        $perPage = (int) $request->input('limit', 50);
        $page = (int) $request->input('page', 1);

        $withdrawals = $query
            ->orderBy('withdrawal_requests.created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'ok' => true,
            'withdrawals' => $withdrawals,
            'total' => $total,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    /**
     * Approve a withdrawal request
     */
    public function approveWithdrawal(Request $request, $withdrawalId)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $withdrawal = DB::table('withdrawal_requests')
            ->where('id', $withdrawalId)
            ->first();

        if (!$withdrawal) {
            return response()->json(['ok' => false, 'message' => 'Withdrawal not found'], 404);
        }

        DB::table('withdrawal_requests')
            ->where('id', $withdrawalId)
            ->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $user->id,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'message' => 'Withdrawal approved']);
    }

    /**
     * Reject a withdrawal request
     * Refunds the amount back to quiz master's available balance
     */
    public function rejectWithdrawal(Request $request, $withdrawalId)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $withdrawal = DB::table('withdrawal_requests')
            ->where('id', $withdrawalId)
            ->first();

        if (!$withdrawal) {
            return response()->json(['ok' => false, 'message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'pending') {
            return response()->json(['ok' => false, 'message' => 'Can only reject pending withdrawals'], 400);
        }

        try {
            DB::transaction(function () use ($withdrawalId, $withdrawal, $request) {
                // Refund the amount back to available balance (not lifetime_earned)
                Wallet::where('user_id', $withdrawal->{'quiz_master_id'})
                    ->increment('available', $withdrawal->amount);
                
                // Update withdrawal status to rejected
                DB::table('withdrawal_requests')
                    ->where('id', $withdrawalId)
                    ->update([
                        'status' => 'rejected',
                        'rejected_at' => now(),
                        'rejection_reason' => $request->input('reason'),
                        'updated_at' => now(),
                    ]);
            });
            
            Log::channel('payment')->info("[Withdrawal] Request REJECTED and REFUNDED", [
                'withdrawal_id' => $withdrawalId,
                'quiz_master_id' => $withdrawal->{'quiz_master_id'},
                'amount' => $withdrawal->amount,
                'reason' => $request->input('reason'),
                'admin_id' => auth()->id(),
            ]);
            
            return response()->json(['ok' => true, 'message' => 'Withdrawal rejected and refunded']);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal] Failed to reject and refund', [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'message' => 'Failed to reject withdrawal'], 500);
        }
    }

    /**
     * Mark withdrawal as paid after admin has sent the money outside the system
     * Updates status, paid_at, and processed_by_admin_id
     */
    public function markWithdrawalAsPaid(Request $request, $withdrawalId)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'transaction_id' => 'nullable|string|max:255', // Optional reference ID for the actual payment
        ]);

        $withdrawal = WithdrawalRequest::find($withdrawalId);

        if (!$withdrawal) {
            return response()->json(['ok' => false, 'message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'approved') {
            return response()->json(['ok' => false, 'message' => 'Only approved withdrawals can be marked as paid'], 400);
        }

        try {
            $withdrawal->update([
                'status' => 'paid',
                'paid_at' => now(),
                'processed_by_admin_id' => $user->id,
            ]);
            
            // If meta exists, store the transaction_id like M-PESA ref
            if ($request->filled('transaction_id')) {
                $meta = $withdrawal->meta ?? [];
                $meta['payment_transaction_id'] = $request->input('transaction_id');
                $withdrawal->update(['meta' => $meta]);
            }
            
            Log::channel('payment')->info("[Withdrawal] Marked as PAID", [
                'withdrawal_id' => $withdrawalId,
                'quiz_master_id' => $withdrawal->{'quiz_master_id'},
                'amount' => $withdrawal->amount,
                'external_tx_id' => $request->input('transaction_id'),
                'admin_id' => $user->id,
            ]);
            
            return response()->json([
                'ok' => true,
                'message' => 'Withdrawal marked as paid',
                'withdrawal' => $withdrawal,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Withdrawal] Failed to mark as paid', [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'message' => 'Failed to mark withdrawal as paid'], 500);
        }
    }

    /**
     * Settle (move) pending funds to available for all users or specific user
     * Admin only - Moves funds from pending â†’ available in wallet
     */
    public function settlePending(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $walletService = new WalletService();

        // If user_id is provided, settle only that user's pending balance
        if ($request->filled('user_id')) {
            $userId = $request->input('user_id');
            $targetUser = User::find($userId);

            if (!$targetUser) {
                return response()->json(['ok' => false, 'message' => 'User not found'], 404);
            }

            $wallet = $walletService->settle($userId);

            if (!$wallet) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Failed to settle pending funds'
                ], 400);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Pending funds settled successfully',
                'wallet' => [
                    'user_id' => $wallet->user_id,
                    'available' => (float) $wallet->available,
                    'pending' => (float) $wallet->pending,
                    'lifetime_earned' => (float) $wallet->lifetime_earned,
                ],
            ]);
        }

        // Settle all pending balances for all users with pending funds
        try {
            $walletsWithPending = Wallet::where('pending', '>', 0)->get();

            if ($walletsWithPending->isEmpty()) {
                return response()->json([
                    'ok' => true,
                    'message' => 'No pending funds to settle',
                    'count' => 0,
                    'total_settled' => 0,
                ]);
            }

            $totalSettled = 0;
            $settledCount = 0;

            foreach ($walletsWithPending as $wallet) {
                $pendingAmount = $wallet->pending;
                $settled = $walletService->settle($wallet->user_id);

                if ($settled) {
                    $totalSettled += $pendingAmount;
                    $settledCount++;
                }
            }

            return response()->json([
                'ok' => true,
                'message' => 'All pending funds settled successfully',
                'count' => $settledCount,
                'total_settled' => (float) $totalSettled,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error settling pending funds: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get/update global settings for approvals, payment revenue share, and quiz prices
     */
    public function settings(Request $request)
    {
        // Allow public GET of settings (read-only) so clients can read defaults.
        // Require admin only for updates (POST/PUT).
        if ($request->method() !== 'GET') {
            $user = auth()->user();
            if (!$user || !$user->is_admin) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        // GET settings
        if ($request->method() === 'GET') {
            $mpesaSetting = PaymentSetting::where('gateway', 'mpesa')->first();
            $revenueShare = $mpesaSetting ? (float) $mpesaSetting->revenue_share : null;

            $defaultQuizPrice = 0.0;
            $defaultBattlePrice = 0.0;

            try {
                $pricing = \App\Models\PricingSetting::singleton();
                $defaultQuizPrice = (float) ($pricing->default_quiz_one_off_price ?? 0);
                $defaultBattlePrice = (float) ($pricing->default_battle_one_off_price ?? 0);
            } catch (\Throwable $e) {
                $defaultQuizPrice = 0.0;
                $defaultBattlePrice = 0.0;
            }

            return response()->json([
                'ok' => true,
                'settings' => [
                    'revenue_share' => $revenueShare,
                    'approvals_enabled' => (boolean) $approvalsEnabled,
                    'mpesa_active' => $mpesaSetting ? (boolean) ($mpesaSetting->is_active ?? true) : true,
                    'default_quiz_price' => $defaultQuizPrice,
                    'default_battle_price' => $defaultBattlePrice,
                    'default_quiz_time_limit' => (integer) config('features.default_quiz_time_limit', 30),
                ],
            ]);
        }

        // UPDATE settings
        if ($request->method() === 'POST' || $request->method() === 'PUT') {
            $validated = $request->validate([
                'revenue_share' => 'sometimes|numeric|min:0|max:100',
                'approvals_enabled' => 'sometimes|boolean',
                'mpesa_active' => 'sometimes|boolean',
                'default_quiz_price' => 'sometimes|numeric|min:0',
                'default_battle_price' => 'sometimes|numeric|min:0',
                'default_quiz_time_limit' => 'sometimes|integer|min:5|max:300',
            ]);

            if (isset($validated['revenue_share'])) {
                $setting = PaymentSetting::where('gateway', 'mpesa')->first();
                if ($setting) {
                    $setting->update(['revenue_share' => $validated['revenue_share']]);
                } else {
                    PaymentSetting::create([
                        'gateway' => 'mpesa',
                        'revenue_share' => $validated['revenue_share'],
                    ]);
                }
            }

            if (isset($validated['approvals_enabled'])) {
                $siteSetting = \App\Models\SiteSetting::current() ?: new \App\Models\SiteSetting();
                $siteSetting->auto_approve_quizzes = $validated['approvals_enabled'];
                $siteSetting->save();
            }

            if (isset($validated['mpesa_active'])) {
                $setting = PaymentSetting::where('gateway', 'mpesa')->first();
                if ($setting) {
                    $setting->update(['is_active' => $validated['mpesa_active']]);
                }
            }

            if (isset($validated['default_quiz_price'])) {
                $pricing = \App\Models\PricingSetting::singleton();
                $pricing->default_quiz_one_off_price = (float) $validated['default_quiz_price'];
                $pricing->save();
            }

            if (isset($validated['default_battle_price'])) {
                $pricing = \App\Models\PricingSetting::singleton();
                $pricing->default_battle_one_off_price = (float) $validated['default_battle_price'];
                $pricing->save();
            }

            if (isset($validated['default_quiz_time_limit'])) {
                // Skip updating non-existent settings table for now
            }

            $mpesaForResponse = PaymentSetting::where('gateway', 'mpesa')->first();
            $pricingSnapshot = null;
            try {
                $pricingSnapshot = \App\Models\PricingSetting::singleton();
            } catch (\Throwable $e) {
            }

            return response()->json([
                'ok' => true,
                'message' => 'Settings updated successfully',
                'settings' => [
                    'revenue_share' => (float) ($validated['revenue_share'] ?? ($mpesaForResponse?->revenue_share ?? 0)),
                    'approvals_enabled' => (boolean) ($validated['approvals_enabled'] ?? true),
                    'mpesa_active' => (boolean) ($validated['mpesa_active'] ?? true),
                    'default_quiz_price' => (float) ($validated['default_quiz_price'] ?? ($pricingSnapshot?->default_quiz_one_off_price ?? 0)),
                    'default_battle_price' => (float) ($validated['default_battle_price'] ?? ($pricingSnapshot?->default_battle_one_off_price ?? 0)),
                    'default_quiz_time_limit' => (integer) ($validated['default_quiz_time_limit'] ?? 30),
                ],
            ]);
        }
    }

    /**
     * Get quizees (same as users endpoint but filtered for quizee role)
     */
    public function quizees(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        // Force role filter to quizee (the `users` endpoint already supports `role`)
        $request->merge(['role' => 'quizee']);
        return $this->users($request);
    }

    /**
     * Get tournaments with participant details
     */
    public function tournaments(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = \App\Models\Tournament::query();

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        // Pagination
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 15);

        $total = $query->count();
        $tournaments = $query->orderBy('start_date', 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->with([
                'subject:id,name',
                'topic:id,name',
                'grade:id,name',
                'creator:id,name',
            ])
            ->withCount('participants')
            ->get()
            ->map(function ($tournament) {
                $participantCount = (int) ($tournament->participants_count ?? 0);

                return [
                    'id' => $tournament->id,
                    'name' => $tournament->name,
                    'description' => $tournament->description,
                    'status' => $tournament->status,
                    'start_date' => $tournament->start_date,
                    'end_date' => $tournament->end_date,
                    'prize_pool' => (float) ($tournament->prize_pool ?? 0),
                    'entry_fee' => (float) ($tournament->entry_fee ?? 0),
                    'max_participants' => $tournament->max_participants,
                    'participant_count' => $participantCount,
                    'participants_count' => $participantCount,
                    'subject' => $tournament->subject?->name,
                    'topic' => $tournament->topic?->name,
                    'grade' => $tournament->grade?->name,
                    'created_by' => $tournament->creator?->name,
                ];
            });

        return response()->json([
            'ok' => true,
            'tournaments' => $tournaments,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /**
     * Get tournament participants with scores
     */
    public function tournamentParticipants(Request $request, $tournamentId)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $tournament = \App\Models\Tournament::findOrFail($tournamentId);

        $query = $tournament->participants()
            ->with(['user', 'attempts'])
            ->orderBy('rank', 'asc');

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Search by user name or email
        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->whereHas('user', function ($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('email', 'like', '%' . $searchTerm . '%');
            });
        }

        // Pagination
        $page = $request->input('page', 1);
        $limit = $request->input('limit', 50);

        $total = $query->count();
        $participants = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get()
            ->map(function ($participant) {
                $attempts = $participant->attempts ?? [];
                $bestScore = $attempts->max('score') ?? 0;
                $attemptCount = count($attempts);

                return [
                    'id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'user_name' => $participant->user->name,
                    'user_email' => $participant->user->email,
                    'status' => $participant->status,
                    'rank' => $participant->rank,
                    'score' => (float) $participant->score,
                    'best_score' => (float) $bestScore,
                    'attempt_count' => $attemptCount,
                    'attempts' => $attempts->map(function ($attempt) {
                        return [
                            'id' => $attempt->id,
                            'score' => (float) $attempt->score,
                            'correct_answers' => $attempt->correct_answers,
                            'total_questions' => $attempt->total_questions,
                            'duration_seconds' => $attempt->duration_seconds,
                            'created_at' => $attempt->created_at,
                        ];
                    })->values(),
                    'joined_at' => $participant->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'status' => $tournament->status,
            ],
            'participants' => $participants,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }
}



