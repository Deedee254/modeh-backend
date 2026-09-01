<?php

namespace App\Observers;

use App\Events\DailyChallengeCompleted;
use App\Models\Notification as AppNotification;
use App\Models\UserDailyChallenge;

class UserDailyChallengeObserver
{
    public function created(UserDailyChallenge $udc)
    {
        try {
            $challenge = $udc->dailyChallenge ? $udc->dailyChallenge->toArray() : ['id' => $udc->daily_challenge_id];
            try {
                AppNotification::create([
                    'user_id' => $udc->user_id,
                    'type' => 'daily_challenge_completed',
                    'data' => ['challenge' => $challenge],
                ]);
            } catch (\Exception $__e) {
            }

            event(new DailyChallengeCompleted($udc->user_id, $challenge));
        } catch (\Exception $e) {
            // ignore
        }
    }
}
