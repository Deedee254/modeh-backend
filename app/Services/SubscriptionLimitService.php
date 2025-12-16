<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Institution;
use App\Models\User;

/**
 * Service for managing subscription limits and access control
 * Handles subscription validation, limit checking, and access prioritization
 * (institution subscriptions take precedence over personal ones)
 */
class SubscriptionLimitService
{
    /**
     * Get the active subscription for a user (personal or institution)
     * Institution subscriptions take precedence if user is part of any institution
     *
     * @param User $user The user to get the active subscription for
     * @return Subscription|null The active subscription if found, null otherwise
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
     *
     * @param \App\Models\Package|null $package The package to get the limit from
     * @param string $limitKey The feature limit key to retrieve (e.g., 'quiz_results', 'battle_results')
     * @return int|null The limit value, or null for unlimited. Defaults to 10 if no package specified
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
     *
     * @param int $userId The user ID to count usage for
     * @return int The number of quiz results revealed today
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
     *
     * @param User $user The user to check limit for
     * @param string $limitKey The feature limit key to check (e.g., 'quiz_results', 'battle_results')
     * @return array{allowed: bool, reason: string|null, limit: int|null, used: int, remaining: int|null}
     *         Array containing:
     *         - allowed: Whether the user can access more results today
     *         - reason: Reason if not allowed (null if allowed)
     *         - limit: The daily limit value (null if unlimited)
     *         - used: Number of results used today
     *         - remaining: Number of results remaining today (null if unlimited)
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
     *
     * @param User $user The user to get subscription details for
     * @return array{subscription_id: int, subscription_type: string, limit: int|null, used: int, remaining: int|null}|null
     *         Array containing subscription details if active, null otherwise:
     *         - subscription_id: The subscription ID
     *         - subscription_type: 'personal' or 'institution'
     *         - limit: Daily limit value (null if unlimited)
     *         - used: Number of results used today
     *         - remaining: Number of results remaining today (null if unlimited)
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

    /**
     * Validate subscription access with helpful error messages
     * Checks both institution and personal subscriptions with proper precedence
     *
     * @param User $user The user to validate subscription for
     * @return array{allowed: bool, message: string|null, subscription_type: string|null, subscription: Subscription|null}
     *         Array containing:
     *         - allowed: Whether user has valid subscription
     *         - message: User-friendly error message if not allowed (null if allowed)
     *         - subscription_type: 'personal', 'institution', or null if no subscription
     *         - subscription: The active subscription object if allowed, null otherwise
     */
    public static function validateSubscriptionAccess($user)
    {
        // Check institution subscriptions first (they take precedence if available)
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
                    // Institution subscription exists and is active
                    return [
                        'allowed' => true,
                        'message' => null,
                        'subscription_type' => 'institution',
                        'subscription' => $instSub
                    ];
                } else {
                    // User is part of institution but subscription is missing or expired
                    $expiredSub = Subscription::where('owner_type', Institution::class)
                        ->where('owner_id', $institution->id)
                        ->orderByDesc('created_at')
                        ->first();
                    
                    if ($expiredSub && $expiredSub->status === 'active' && $expiredSub->ends_at && $expiredSub->ends_at <= now()) {
                        return [
                            'allowed' => false,
                            'message' => "Your institution's package has expired. Please contact your institution administrator to renew the subscription.",
                            'subscription_type' => 'institution',
                            'subscription' => null
                        ];
                    }
                }
            }
        }
        
        // Fall back to personal subscription
        $personalSub = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();
        
        if ($personalSub) {
            return [
                'allowed' => true,
                'message' => null,
                'subscription_type' => 'personal',
                'subscription' => $personalSub
            ];
        }

        // Check if personal subscription exists but is expired
        $expiredPersonalSub = Subscription::where('user_id', $user->id)
            ->where('owner_type', User::class)
            ->where('status', 'active')
            ->where('ends_at', '<=', now())
            ->orderByDesc('ends_at')
            ->first();
        
        if ($expiredPersonalSub) {
            return [
                'allowed' => false,
                'message' => 'Your subscription has expired. Please renew your subscription to continue.',
                'subscription_type' => 'personal',
                'subscription' => null
            ];
        }

        // No subscription at all
        return [
            'allowed' => false,
            'message' => 'You need an active subscription to access this feature.',
            'subscription_type' => null,
            'subscription' => null
        ];
    }
}
