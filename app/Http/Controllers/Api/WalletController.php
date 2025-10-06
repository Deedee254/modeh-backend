<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\Auth;

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
        $q = Transaction::query()->where('tutor_id', $user->id)->orderBy('created_at', 'desc');
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

        // debit available and create withdrawal request
        $wallet->available = bcsub($wallet->available, $amount, 2);
        $wallet->save();

        $wr = WithdrawalRequest::create(['tutor_id' => $user->id, 'amount' => $amount, 'method' => $request->input('method', 'mpesa'), 'status' => 'pending', 'meta' => $request->input('meta', [])]);

        // Broadcast new withdrawal request to tutor (and admins if needed)
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
        $list = WithdrawalRequest::where('tutor_id', $user->id)->orderBy('created_at', 'desc')->get();
        return response()->json(['ok' => true, 'withdrawals' => $list]);
    }

    /**
     * Return rewards summary for the authenticated student
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
