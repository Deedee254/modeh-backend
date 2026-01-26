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
     * If context is provided (subscription_type, institution_id), it will try to return that specific one.
     * Otherwise, institution subscriptions take precedence if user is part of any institution.
     *
     * @param User $user The user to get the active subscription for
     * @param array $context Optional parameters for specific subscription selection
     * @return Subscription|null The active subscription if found, null otherwise
     */
    public static function getActiveSubscription($user, $context = [])
    {
        $preferredType = $context['subscription_type'] ?? null;
        $institutionId = $context['institution_id'] ?? null;

        // If institution is preferred, try to find it first
        if ($preferredType === 'institution' && $institutionId) {
            $instSub = Subscription::where('owner_type', Institution::class)
                ->where('owner_id', $institutionId)
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

        // If personal is preferred, try to find it first
        if ($preferredType === 'personal') {
            $personalSub = Subscription::where('user_id', $user->id)
                ->where('owner_type', User::class)
                ->where('status', 'active')
                ->where(function ($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('started_at')
                ->first();
            if ($personalSub) {
                return $personalSub;
            }
        }

        // Default behavior: Check for institution subscriptions first (they take precedence)
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
     * Count how many results were revealed today for a specific subscription and usage type
     *
     * @param int $userId The user ID to count usage for
     * @param int|null $subscriptionId The subscription ID to count usage for (null counts all)
     * @param string $limitKey The feature limit key to count (e.g., 'quiz_results', 'battle_results')
     * @return int The number of results revealed today
     */
    public static function countTodayUsage($userId, $subscriptionId = null, $limitKey = 'quiz_results')
    {
        $today = now()->startOfDay();

        if ($limitKey === 'battle_results') {
            $query = \App\Models\Battle::where(function ($q) use ($userId) {
                // Battles can involve the user as initiator or opponent
                $q->where('initiator_id', $userId)->orWhere('opponent_id', $userId);
            })
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $today);

            if ($subscriptionId !== null) {
                $query->where('subscription_id', $subscriptionId);
            }
            return $query->count();
        }

        // Default to quiz attempts
        $query = \App\Models\QuizAttempt::where('user_id', $userId)
            ->whereNotNull('score')
            ->where('created_at', '>=', $today);

        if ($subscriptionId !== null) {
            $query->where('subscription_id', $subscriptionId);
        }

        return $query->count();
    }

    /**
     * Check if user has reached daily limit and return limit info
     *
     * @param User $user The user to check limit for
     * @param string $limitKey The feature limit key to check (e.g., 'quiz_results', 'battle_results')
     * @param array $context Optional parameters for subscription selection
     * @return array{allowed: bool, reason: string|null, limit: int|null, used: int, remaining: int|null}
     */
    public static function checkDailyLimit($user, $limitKey = 'quiz_results', $context = [])
    {
        $activeSub = self::getActiveSubscription($user, $context);

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
        $used = self::countTodayUsage($user->id, $activeSub->id, $limitKey);
        
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
     * @param array $context Optional parameters for subscription selection
     * @return array{subscription_id: int, subscription_type: string, limit: int|null, used: int, remaining: int|null}|null
     *         Array containing subscription details if active, null otherwise:
     *         - subscription_id: The subscription ID
     *         - subscription_type: 'personal' or 'institution'
     *         - limit: Daily limit value (null if unlimited)
     *         - used: Number of results used today
     *         - remaining: Number of results remaining today (null if unlimited)
     */
    public static function getSubscriptionDetails($user, $context = [])
    {
        $activeSub = self::getActiveSubscription($user, $context);
        
        if (!$activeSub) {
            return null;
        }
        
        $type = $activeSub->owner_type === Institution::class ? 'institution' : 'personal';
        $limit = self::getPackageLimit($activeSub->package, 'quiz_results');
        $used = self::countTodayUsage($user->id, $activeSub->id);
        
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
     * @param array $context Optional parameters for subscription selection
     * @return array{allowed: bool, message: string|null, subscription_type: string|null, subscription: Subscription|null}
     */
    public static function validateSubscriptionAccess($user, $context = [])
    {
        $preferredType = $context['subscription_type'] ?? null;
        $institutionId = $context['institution_id'] ?? null;

        // If institution is preferred, check it first
        if ($preferredType === 'institution' && $institutionId) {
            $instSub = Subscription::where('owner_type', Institution::class)
                ->where('owner_id', $institutionId)
                ->where('status', 'active')
                ->where(function ($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('started_at')
                ->first();
            
            if ($instSub) {
                return [
                    'allowed' => true,
                    'message' => null,
                    'subscription_type' => 'institution',
                    'subscription' => $instSub
                ];
            }
        }

        // If personal is preferred, check it first
        if ($preferredType === 'personal') {
            $personalSub = Subscription::where('user_id', $user->id)
                ->where('owner_type', User::class)
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
        }

        // Default behavior: Check institution subscriptions first (they take precedence if available)
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
