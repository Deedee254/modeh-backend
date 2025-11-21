<?php

namespace App\Filament\Resources\InstitutionResource\Pages;

use App\Models\Institution;
use App\Models\User;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use App\Filament\Resources\InstitutionResource;

class MemberAnalytics extends Page
{
    protected static string $resource = InstitutionResource::class;
    protected string $view = 'filament.resources.institution-resource.pages.member-analytics';
    protected static ?string $title = 'Member Analytics';
    
    public Institution $institution;
    public User $user;
    public array $analyticsData = [];

    public function mount($institution, $user): void
    {
        $this->institution = $institution;
        $this->user = $user;
        $this->authorize('view', $this->institution);
        $this->loadAnalytics();
    }

    public function loadAnalytics(): void
    {
        try {
            // Member info
            $pivotData = DB::table('institution_user')
                ->where('institution_id', $this->institution->id)
                ->where('user_id', $this->user->id)
                ->first();

            $joinedAt = $pivotData?->created_at ? \Carbon\Carbon::parse($pivotData->created_at) : null;
            $role = $pivotData?->role ?? 'unknown';

            // Quiz attempts
            $attempts = DB::table('quiz_attempts')
                ->where('user_id', $this->user->id)
                ->get();

            $totalAttempts = $attempts->count();
            $avgScore = $totalAttempts > 0 ? round($attempts->avg('score'), 2) : 0;
            $highestScore = $totalAttempts > 0 ? $attempts->max('score') : 0;
            $lowestScore = $totalAttempts > 0 ? $attempts->min('score') : 0;

            // Last activity
            $lastAttempt = DB::table('quiz_attempts')
                ->where('user_id', $this->user->id)
                ->latest('created_at')
                ->first();

            $lastActivity = $lastAttempt?->created_at ? \Carbon\Carbon::parse($lastAttempt->created_at) : null;

            // Score distribution
            $scoreRanges = [
                '0-20' => $attempts->where('score', '<', 20)->count(),
                '20-40' => $attempts->whereBetween('score', [20, 39])->count(),
                '40-60' => $attempts->whereBetween('score', [40, 59])->count(),
                '60-80' => $attempts->whereBetween('score', [60, 79])->count(),
                '80-100' => $attempts->where('score', '>=', 80)->count()
            ];

            // Activity trend (last 7 days)
            $activityTrend = [];
            
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $count = $attempts->filter(function ($attempt) use ($date) {
                    return \Carbon\Carbon::parse($attempt->created_at)->format('Y-m-d') === $date;
                })->count();
                $activityTrend[$date] = $count;
            }

            $this->analyticsData = [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $role,
                'joined_at' => $joinedAt?->format('M d, Y'),
                'joined_at_relative' => $joinedAt?->diffForHumans(),
                'quizzes' => [
                    'total_attempts' => $totalAttempts,
                    'avg_score' => $avgScore,
                    'highest_score' => $highestScore,
                    'lowest_score' => $lowestScore
                ],
                'last_activity' => $lastActivity?->format('M d, Y H:i'),
                'last_activity_relative' => $lastActivity?->diffForHumans(),
                'score_distribution' => $scoreRanges,
                'activity_trend' => $activityTrend
            ];
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error Loading Member Analytics')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getHeading(): string
    {
        return 'Analytics: ' . $this->user->name . ' (' . $this->institution->name . ')';
    }
}
