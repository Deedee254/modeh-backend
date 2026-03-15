<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\PaymentSetting;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $totalRevenue = (float) Transaction::where('status', 'confirmed')->sum('amount');

        // Sum platform and QM shares
        $platformShare = (float) Transaction::where('status', 'confirmed')->sum('platform_share');
        $quizMasterShare = (float) Transaction::where('status', 'confirmed')->sum('quiz-master_share');

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

        // Get revenue share percentage
        $revenueShare = (float) (PaymentSetting::where('gateway', 'mpesa')->first()?->revenue_share ?? 60.0);

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

        $query = Transaction::query();

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
                return [
                    'id' => $tx->id,
                    'tx_id' => $tx->tx_id,
                    'user_id' => $tx->user_id,
                    'quiz-master_id' => $tx->{'quiz-master_id'},
                    'quiz_id' => $tx->quiz_id,
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

        $query = User::where('role', 'quiz-master');

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
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($user) {
                // Get stats for this quiz master
                $totalEarnings = (float) Transaction::where('quiz-master_id', $user->id)
                    ->where('status', 'confirmed')
                    ->sum('quiz-master_share');

                $transactionCount = Transaction::where('quiz-master_id', $user->id)
                    ->where('status', 'confirmed')
                    ->count();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'total_earnings' => $totalEarnings,
                    'transaction_count' => $transactionCount,
                    'created_at' => $user->created_at,
                ];
            });

        return response()->json([
            'ok' => true,
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
            ->join('users', 'withdrawal_requests.quiz-master_id', '=', 'users.id')
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

        DB::table('withdrawal_requests')
            ->where('id', $withdrawalId)
            ->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'rejection_reason' => $request->input('reason'),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'message' => 'Withdrawal rejected']);
    }

    /**
     * Settle (move) pending funds to available for all users or specific user
     * Admin only - Moves funds from pending → available in wallet
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
}
