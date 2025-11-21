<?php

namespace App\Filament\Resources\InstitutionResource\Pages;

use App\Models\Institution;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use App\Filament\Resources\InstitutionResource;

class InstitutionAnalytics extends Page
{
    protected static string $resource = InstitutionResource::class;
    protected string $view = 'filament.resources.institution-resource.pages.institution-analytics';
    protected static ?string $title = 'Institution Analytics';
    
    public Institution $institution;
    public array $analyticsData = [];

    public function mount($record): void
    {
        $this->institution = $record;
        $this->authorize('view', $this->institution);
        $this->loadAnalytics();
    }

    public function loadAnalytics(): void
    {
        try {
            $now = now();
            $weekAgo = now()->subDays(7);

            $memberIds = $this->institution->users()->pluck('users.id')->toArray();

            // Member counts
            $totalMembers = $this->institution->users()->count();
            $quizees = $this->institution->users()->wherePivot('role', 'quizee')->count();
            $quizMasters = $this->institution->users()->wherePivot('role', 'quiz-master')->count();

            // Activity
            $activeToday = empty($memberIds) ? 0 : DB::table('quiz_attempts')
                ->whereIn('user_id', $memberIds)
                ->whereDate('created_at', $now)
                ->count();

            $activeThisWeek = empty($memberIds) ? 0 : DB::table('users')
                ->whereIn('id', $memberIds)
                ->whereHas('quizAttempts', function ($q) use ($weekAgo, $now) {
                    $q->whereBetween('created_at', [$weekAgo, $now]);
                })->count();

            // Quiz attempts
            $attempts = empty($memberIds) ? collect([]) : DB::table('quiz_attempts')->whereIn('user_id', $memberIds)->get();
            $totalAttempts = $attempts->count();
            $avgScore = $totalAttempts > 0 ? round($attempts->avg('score'), 2) : 0;

            // Subscription
            $activeSub = $this->institution->activeSubscription();
            $seatsTotal = $activeSub && $activeSub->package ? $activeSub->package->seats : 0;
            $seatsAssigned = $activeSub ? $activeSub->assignments()->whereNull('revoked_at')->count() : 0;
            $seatsAvailable = $seatsTotal > 0 ? $seatsTotal - $seatsAssigned : 0;
            $utilizationRate = $seatsTotal > 0 ? round(($seatsAssigned / $seatsTotal) * 100, 2) : 0;

            $this->analyticsData = [
                'members' => [
                    'total' => $totalMembers,
                    'quizees' => $quizees,
                    'quiz_masters' => $quizMasters,
                    'active_today' => $activeToday,
                    'active_this_week' => $activeThisWeek
                ],
                'quizzes' => [
                    'total_attempts' => $totalAttempts,
                    'avg_score' => $avgScore
                ],
                'subscription' => [
                    'seats_total' => $seatsTotal,
                    'seats_assigned' => $seatsAssigned,
                    'seats_available' => $seatsAvailable,
                    'utilization_rate' => $utilizationRate
                ]
            ];
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error Loading Analytics')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getHeading(): string
    {
        return 'Analytics: ' . $this->institution->name;
    }
}
