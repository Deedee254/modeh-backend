<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\User;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Question;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\QuizAttempt;

class AdminStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    use InteractsWithPageFilters;

    protected function getStats(): array
    {
        $start = $this->pageFilters['startDate'] ?? null;
        $end = $this->pageFilters['endDate'] ?? null;

        // Helpful helpers to apply date filters when provided
        $usersQuery = User::query();
        $quizzesQuery = Quiz::query();
        $transactionsQuery = Transaction::query();

        if ($start) {
            $usersQuery->whereDate('created_at', '>=', $start);
            $quizzesQuery->whereDate('created_at', '>=', $start);
            $transactionsQuery->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $usersQuery->whereDate('created_at', '<=', $end);
            $quizzesQuery->whereDate('created_at', '<=', $end);
            $transactionsQuery->whereDate('created_at', '<=', $end);
        }

        // taxonomy / creator filters
        $level = $this->pageFilters['level'] ?? null;
        $grade = $this->pageFilters['grade'] ?? null;
        $creator = $this->pageFilters['creator'] ?? null;

        if ($level) {
            $quizzesQuery->where('level_id', $level);
        }
        if ($grade) {
            $quizzesQuery->where('grade_id', $grade);
        }
        if ($creator) {
            $quizzesQuery->where('user_id', $creator);
        }

        // Apply quiz-related filters to transactions by limiting to matching quiz ids
        if ($level || $grade || $creator) {
            $quizIds = $quizzesQuery->pluck('id')->toArray();
            if (count($quizIds) > 0) {
                $transactionsQuery->whereIn('quiz_id', $quizIds);
            } else {
                // no matching quizzes -> no transactions
                $transactionsQuery->whereRaw('1 = 0');
            }
        }

        $totalUsers = $usersQuery->count();
        // new users in last 7 days relative to now (filtering the window by dashboard filters is redundant for this value,
        // so we preserve previous behaviour but still show 7-day delta)
        $newUsers7 = User::where('created_at', '>=', now()->subDays(7))->count();

        $totalQuizzes = $quizzesQuery->count();
        $quizzes7 = Quiz::where('created_at', '>=', now()->subDays(7))->count();

        $activeSubscriptions = Subscription::where('status', 'active')->count();

        $revenue30 = $transactionsQuery->where('status', 'success')
            ->sum('amount');

        $pendingApprovals = Subject::where('is_approved', false)->count()
            + Topic::where('is_approved', false)->count()
            + Question::where('is_approved', false)->count();

        // Average score across quiz attempts (respecting filters)
        $avgScoreQuery = QuizAttempt::query();
        if ($start) {
            $avgScoreQuery->whereDate('created_at', '>=', $start);
        }
        if ($end) {
            $avgScoreQuery->whereDate('created_at', '<=', $end);
        }
        if ($level || $grade || $creator) {
            // apply quiz-related filters by joining to quizzes
            $avgScoreQuery->whereHas('quiz', function ($q) use ($level, $grade, $creator) {
                if ($level) $q->where('level_id', $level);
                if ($grade) $q->where('grade_id', $grade);
                if ($creator) $q->where('user_id', $creator);
            });
        }
        $avgScore = $avgScoreQuery->avg('score') ?? 0;

        return [
            Stat::make('Users', $totalUsers)->description("{$newUsers7} new in 7d"),
            Stat::make('Quizzes', $totalQuizzes)->description("{$quizzes7} created in 7d"),
            Stat::make('Active Subscriptions', $activeSubscriptions),
            Stat::make('Revenue (30d)', number_format($revenue30, 2)),
            Stat::make('Pending approvals', $pendingApprovals),
            Stat::make('Average score', number_format($avgScore, 2)),
        ];
    }
}
