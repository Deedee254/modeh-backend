<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
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
            event(new \App\Events\WithdrawalRequestUpdated($wr->toArray(), $user->id));
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
            event(new \App\Events\WalletUpdated($wallet->toArray(), $quizMasterId));
        } catch (\Throwable $_) {}

        return response()->json(['ok' => true, 'wallet' => $wallet]);
    }

    /**
     * Return rewards summary for the authenticated quizee
     * { points, vouchers, nextThreshold, package (optional) }
     */
    public function rewardsMy()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);

        // Basic rewards summary using available fields
        $points = $user->points ?? 0;
        // find any vouchers if Voucher model exists (best-effort)
        $vouchers = [];
        if (class_exists('\App\\Models\\Voucher') && method_exists($user, 'vouchers')) {
            try { $vouchers = $user->vouchers()->where('redeemed', false)->get(); } catch (\Throwable $_) { $vouchers = []; }
        }

        // Next threshold heuristic: next multiple of 500
        $nextThreshold = (ceil(($points + 1) / 500) * 500);

        return response()->json(['ok' => true, 'points' => $points, 'vouchers' => $vouchers, 'nextThreshold' => $nextThreshold]);
    }
}
