<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\DailyUsageTracking;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    public function mine(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false], 401);
        
        // Get personal subscriptions
        $subs = Subscription::with('package')->where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
        $primarySub = $subs->first();
        
        // Get institution subscriptions if user belongs to institutions
        $institutionSubs = [];
        if ($user->institutions && $user->institutions->count() > 0) {
            foreach ($user->institutions as $institution) {
                $instSub = Subscription::with('package')
                    ->where('owner_type', \App\Models\Institution::class)
                    ->where('owner_id', $institution->id)
                    ->where('status', 'active')
                    ->first();
                if ($instSub) {
                    $institutionSubs[] = [
                        'id' => $instSub->id,
                        'institution_id' => $institution->id,
                        'institution_name' => $institution->name,
                        'package_id' => $instSub->package_id,
                        'package' => $instSub->package,
                        'status' => $instSub->status,
                        'limit' => self::getPackageLimit($instSub->package, 'quiz_results'),
                        'type' => 'institution'
                    ];
                }
            }
        }
        
        // Calculate remaining for personal subscription
        if ($primarySub) {
            $limit = self::getPackageLimit($primarySub->package, 'quiz_results');
            $used = $this->countTodayUsage($user->id);
            $primarySub->limit = $limit;
            $primarySub->used = $used;
            $primarySub->remaining = $limit ? max(0, $limit - $used) : null;
            $primarySub->type = 'personal';
        }
        
        // Calculate remaining for institution subscriptions
        foreach ($institutionSubs as &$instSub) {
            $limit = $instSub['limit'];
            $used = $this->countTodayUsage($user->id);
            $instSub['used'] = $used;
            $instSub['remaining'] = $limit ? max(0, $limit - $used) : null;
        }
        
        return response()->json([
            'ok' => true,
            'subscriptions' => $subs,
            'subscription' => $primarySub,
            'institution_subscriptions' => $institutionSubs,
            'has_institution' => count($institutionSubs) > 0
        ]);
    }
    
    /**
     * Get limit from package features, handle unlimited (null)
     */
    private static function getPackageLimit($package, $limitKey = 'quiz_results')
    {
        if (!$package) return 10; // default
        if (!is_array($package->features)) return 10;
        
        $limits = $package->features['limits'] ?? [];
        $limit = $limits[$limitKey] ?? 10;
        
        return $limit; // null = unlimited, number = limit
    }
    
    /**
     * Count how many results were revealed today
     */
    private function countTodayUsage($userId)
    {
        $today = now()->startOfDay();
        return \App\Models\QuizAttempt::where('user_id', $userId)
            ->whereNotNull('score')
            ->where('created_at', '>=', $today)
            ->count();
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
