<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    public function mine(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        $subs = Subscription::with('package')->where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
        return response()->json(['ok' => true, 'subscriptions' => $subs, 'subscription' => $subs->first()]);
    }

    // Public check by tx id (useful for frontend polling when tx known)
    public function statusByTx(Request $request)
    {
        $tx = $request->query('tx');
        if (!$tx) return response()->json(['ok' => false, 'message' => 'tx required'], 400);
        $sub = Subscription::where('gateway_meta->tx', $tx)->with('package')->first();
        if (!$sub) return response()->json(['ok' => false, 'message' => 'subscription not found'], 404);
        return response()->json(['ok' => true, 'subscription' => $sub, 'status' => $sub->status]);
    }

    // Authenticated check by subscription id
    public function status(Request $request, Subscription $subscription)
    {
        $user = Auth::user();
        if (!$user || $subscription->user_id !== $user->id) return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        return response()->json(['ok' => true, 'subscription' => $subscription, 'status' => $subscription->status]);
    }
}
