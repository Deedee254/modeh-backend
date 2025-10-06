<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Models\UserBadge;
use App\Models\UserDailyChallenge;
use App\Models\Battle;
use App\Observers\UserBadgeObserver;
use App\Observers\UserDailyChallengeObserver;
use App\Observers\BattleObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewFilament', function ($user = null) {
            return $user && $user->role === 'admin';
        });

        // Register observers to broadcast gamification events
        UserBadge::observe(UserBadgeObserver::class);
        UserDailyChallenge::observe(UserDailyChallengeObserver::class);
        Battle::observe(BattleObserver::class);
    }
}
