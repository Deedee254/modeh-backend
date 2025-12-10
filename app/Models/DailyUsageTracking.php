<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class DailyUsageTracking extends Model
{
    use HasFactory;

    protected $table = 'daily_usage_tracking';
    protected $fillable = ['user_id', 'subscription_id', 'tracking_date', 'usage_type', 'used'];
    protected $casts = [
        'tracking_date' => 'date',
        'used' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get or create today's usage tracking for a user
     */
    public static function getOrCreateToday($userId, $usageType = 'reveals', $subscriptionId = null)
    {
        return self::firstOrCreate([
            'user_id' => $userId,
            'tracking_date' => Carbon::today(),
            'usage_type' => $usageType,
        ], [
            'subscription_id' => $subscriptionId,
            'used' => 0,
        ]);
    }

    /**
     * Increment usage count for today
     */
    public static function incrementToday($userId, $amount = 1, $usageType = 'reveals', $subscriptionId = null)
    {
        $tracking = self::getOrCreateToday($userId, $usageType, $subscriptionId);
        $tracking->increment('used', $amount);
        return $tracking;
    }

    /**
     * Get today's usage for a user
     */
    public static function getTodayForUser($userId, $usageType = 'reveals')
    {
        return self::where('user_id', $userId)
            ->where('tracking_date', Carbon::today())
            ->where('usage_type', $usageType)
            ->first();
    }
}
