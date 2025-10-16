<?php

namespace App\Observers;

use App\Models\UserBadge;
use App\Events\BadgeAwarded;
use App\Models\Notification as AppNotification;

class UserBadgeObserver
{
    public function created(UserBadge $userBadge)
    {
        try {
            $badge = $userBadge->badge ? $userBadge->badge->toArray() : ['id' => $userBadge->badge_id];
            // persist a notification record for history
            try {
                AppNotification::create([
                    'user_id' => $userBadge->user_id,
                    'type' => 'badge_awarded',
                    'data' => ['badge' => $badge],
                ]);
            } catch (\Exception $__e) {
                // ignore create errors
            }

            event(new BadgeAwarded($userBadge->user_id, $badge));
        } catch (\Exception $e) {
            // don't break application flow if broadcasting fails
        }
    }
}
