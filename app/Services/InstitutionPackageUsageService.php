<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\InstitutionPackageUsage;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * InstitutionPackageUsageService
 * 
 * Tracks and enforces institution package limits:
 * - Seats: Number of members that can be assigned
 * - Quiz attempts: Number of quiz attempts per day/month
 */
class InstitutionPackageUsageService
{
    /**
     * Record a quiz attempt for an institution member
     * 
     * @param Institution $institution
     * @param User $user
     * @param Subscription|null $subscription The institution's active subscription (if any)
     * @param array $metadata Additional context (quiz_id, etc)
     * @return void
     */
    public static function recordQuizAttempt(
        Institution $institution,
        User $user,
        ?Subscription $subscription = null,
        array $metadata = []
    ): void {
        InstitutionPackageUsage::create([
            'institution_id' => $institution->id,
            'subscription_id' => $subscription?->id,
            'user_id' => $user->id,
            'usage_type' => 'quiz_attempt',
            'count' => 1,
            'usage_date' => Carbon::now()->toDateString(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Record a seat assignment for a new institution member
     * 
     * @param Institution $institution
     * @param User $user
     * @param Subscription|null $subscription The institution's active subscription (if any)
     * @return void
     */
    public static function recordSeatUsage(
        Institution $institution,
        User $user,
        ?Subscription $subscription = null
    ): void {
        InstitutionPackageUsage::create([
            'institution_id' => $institution->id,
            'subscription_id' => $subscription?->id,
            'user_id' => $user->id,
            'usage_type' => 'seat',
            'count' => 1,
            'usage_date' => Carbon::now()->toDateString(),
        ]);
    }

    /**
     * Check if institution has available seats in their package
     * 
     * @param Institution $institution
     * @return array{
     *     has_limit: bool,
     *     available: int|null,
     *     used: int,
     *     limit: int|null,
     *     message: string
     * }
     */
    public static function checkSeatsAvailable(Institution $institution): array
    {
        // Get active institution subscription
        $subscription = Subscription::where('owner_type', 'App\Models\Institution')
            ->where('owner_id', $institution->id)
            ->where('status', 'active')
            ->first();

        if (!$subscription || !$subscription->package) {
            return [
                'has_limit' => false,
                'available' => null,
                'used' => 0,
                'limit' => null,
                'message' => 'No active package'
            ];
        }

        $limit = $subscription->package->seats;

        if (is_null($limit)) {
            return [
                'has_limit' => false,
                'available' => null,
                'used' => 0,
                'limit' => null,
                'message' => 'Unlimited seats'
            ];
        }

        // Count unique users in institution
        $used = $institution->users()->count();
        $available = max(0, $limit - $used);

        return [
            'has_limit' => true,
            'available' => $available,
            'used' => $used,
            'limit' => $limit,
            'message' => "Used {$used}/{$limit} seats"
        ];
    }

    /**
     * Check if institution can attempt quiz (if there are daily attempt limits)
     * 
     * @param Institution $institution
     * @return array{
     *     has_limit: bool,
     *     available: int|null,
     *     used_today: int,
     *     limit: int|null,
     *     message: string
     * }
     */
    public static function checkQuizAttemptsAvailable(Institution $institution): array
    {
        $subscription = Subscription::where('owner_type', 'App\Models\Institution')
            ->where('owner_id', $institution->id)
            ->where('status', 'active')
            ->first();

        if (!$subscription || !$subscription->package) {
            return [
                'has_limit' => false,
                'available' => null,
                'used_today' => 0,
                'limit' => null,
                'message' => 'No active package'
            ];
        }

        $limit = $subscription->package->metadata['quiz_attempts_daily'] ?? null;

        if (is_null($limit)) {
            return [
                'has_limit' => false,
                'available' => null,
                'used_today' => 0,
                'limit' => null,
                'message' => 'Unlimited daily attempts'
            ];
        }

        $today = Carbon::now()->toDateString();
        $usedToday = InstitutionPackageUsage::where('institution_id', $institution->id)
            ->where('subscription_id', $subscription->id)
            ->where('usage_type', 'quiz_attempt')
            ->where('usage_date', $today)
            ->sum('count');

        $available = max(0, $limit - $usedToday);

        return [
            'has_limit' => true,
            'available' => $available,
            'used_today' => $usedToday,
            'limit' => $limit,
            'message' => "Used {$usedToday}/{$limit} attempts today"
        ];
    }

    /**
     * Get usage report for an institution
     * 
     * @param Institution $institution
     * @param string $startDate Format: Y-m-d
     * @param string $endDate Format: Y-m-d
     * @return array
     */
    public static function getUsageReport(
        Institution $institution,
        string $startDate = '',
        string $endDate = ''
    ): array {
        $query = InstitutionPackageUsage::where('institution_id', $institution->id);

        if ($startDate) {
            $query->where('usage_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('usage_date', '<=', $endDate);
        }

        $usage = $query->get();

        return [
            'total_seat_uses' => $usage->where('usage_type', 'seat')->sum('count'),
            'total_attempts' => $usage->where('usage_type', 'quiz_attempt')->sum('count'),
            'unique_users' => $usage->pluck('user_id')->unique()->count(),
            'by_date' => $usage->groupBy('usage_date')->map(fn ($items) => [
                'seats' => $items->where('usage_type', 'seat')->sum('count'),
                'attempts' => $items->where('usage_type', 'quiz_attempt')->sum('count'),
            ]),
        ];
    }
}
