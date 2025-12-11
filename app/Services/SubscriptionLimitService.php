<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Institution;

class SubscriptionLimitService
{
    /**
     * Get the active subscription for a user (personal or institution)
     * Institution subscriptions take precedence
     */
    public static function getActiveSubscription($user)
    {
        // Check for institution subscriptions first (they take precedence)
        if ($user->institutions && $user->institutions->count() > 0) {
            foreach ($user->institutions as $institution) {
                $instSub = Subscription::where('owner_type', Institution::class)
                    ->where('owner_id', $institution->id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $now = now();
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                    })
                    ->orderByDesc('started_at')
                    ->first();
                if ($instSub) {
                    return $instSub;
                }
            }
        }
        
        // Fall back to personal subscription
        return Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();
    }
    
    /**
     * Get the limit from a subscription's package
     * Returns null for unlimited, or the numeric limit
     */
    public static function getPackageLimit($package, $limitKey = 'quiz_results')
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
    public static function countTodayUsage($userId)
    {
        $today = now()->startOfDay();
        return \App\Models\QuizAttempt::where('user_id', $userId)
            ->whereNotNull('score')
            ->where('created_at', '>=', $today)
            ->count();
    }
    
    /**
     * Check if user has reached daily limit and return limit info
     */
    public static function checkDailyLimit($user, $limitKey = 'quiz_results')
    {
        $activeSub = self::getActiveSubscription($user);
        
        if (!$activeSub) {
            return [
                'allowed' => false,
                'reason' => 'No active subscription',
                'limit' => null,
                'used' => 0,
                'remaining' => 0
            ];
        }
        
        $limit = self::getPackageLimit($activeSub->package, $limitKey);
        $used = self::countTodayUsage($user->id);
        
        // null = unlimited
        if ($limit === null) {
            return [
                'allowed' => true,
                'limit' => null,
                'used' => $used,
                'remaining' => null
            ];
        }
        
        $remaining = max(0, $limit - $used);
        
        return [
            'allowed' => $remaining > 0,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining
        ];
    }
    
    /**
     * Get active subscription details including type (personal or institution)
     */
    public static function getSubscriptionDetails($user)
    {
        $activeSub = self::getActiveSubscription($user);
        
        if (!$activeSub) {
            return null;
        }
        
        $type = $activeSub->owner_type === Institution::class ? 'institution' : 'personal';
        $limit = self::getPackageLimit($activeSub->package, 'quiz_results');
        $used = self::countTodayUsage($user->id);
        
        return [
            'subscription_id' => $activeSub->id,
            'subscription_type' => $type,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit ? max(0, $limit - $used) : null
        ];
    }
}
