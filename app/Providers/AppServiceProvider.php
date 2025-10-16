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
use App\Observers\QuizObserver;
use App\Models\Quiz;

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
            // Allow unauthenticated users to reach the Filament login page.
            // Filament may evaluate the gate while serving the panel route, so
            // permit access to the login path and root admin path for guests.
            try {
                $path = request()->path();
            } catch (\Throwable $e) {
                $path = null;
            }

            if (in_array($path, ['admin', 'admin/login'], true)) {
                return true;
            }

            return $user && $user->role === 'admin';
        });

        // Register observers to broadcast gamification events
        UserBadge::observe(UserBadgeObserver::class);
        UserDailyChallenge::observe(UserDailyChallengeObserver::class);
        Battle::observe(BattleObserver::class);
    Quiz::observe(QuizObserver::class);
    }
}
