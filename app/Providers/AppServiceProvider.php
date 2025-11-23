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
use App\Observers\DashboardCacheInvalidationObserver;
use App\Observers\TournamentBattleObserver;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\TournamentBattle;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Policies\TournamentPolicy;
use App\Models\Tournament;

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
use App\Policies\TournamentPolicy;
use App\Policies\QuizPolicy;
     * Bootstrap any application services.
     */
    public function boot(): void
    {
    // Register model policies
    Gate::policy(\App\Models\Quiz::class, \App\Policies\QuizPolicy::class);
    Gate::policy(\App\Models\Tournament::class, \App\Policies\TournamentPolicy::class);
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
        TournamentBattle::observe(TournamentBattleObserver::class);

        // Register lightweight cache invalidation listeners for dashboard widgets
        $flush = function ($model = null) {
            try {
                if (Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
                    Cache::tags(['dashboard_charts'])->flush();
                } else {
                    Cache::flush();
                }
            } catch (\Throwable $e) {
                logger()->warning('Failed to flush dashboard cache: ' . $e->getMessage());
            }
        };

        // Attach to key models that affect the dashboard charts
        User::created($flush);
        User::updated($flush);
        User::deleted($flush);

        Quiz::created($flush);
        Quiz::updated($flush);
        Quiz::deleted($flush);

        // Explicitly register Livewire admin components aliases (fix component-not-found errors)
        try {
            // Register Livewire component alias only when Livewire is installed and available.
            if (class_exists('\\Livewire\\Livewire')) {
                call_user_func(['\\Livewire\\Livewire', 'component'], 'admin.bank-questions-table', \App\Http\Livewire\Admin\BankQuestionsTable::class);
            } else {
                logger()->debug('Livewire not present, skipping admin component registration');
            }
        } catch (\Throwable $e) {
            // Don't block boot if Livewire registration fails in some contexts
            logger()->debug('Livewire component registration skipped: ' . $e->getMessage());
        }

        QuizAttempt::created($flush);
        QuizAttempt::updated($flush);
        QuizAttempt::deleted($flush);
    }
}
