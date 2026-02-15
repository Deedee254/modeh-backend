<?php

namespace App\Filament\Widgets;

use Filament\Widgets\LineChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\User;
use App\Models\QuizAttempt;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class RegistrationsQuizzesScoresChart extends LineChartWidget
{
    protected static ?int $sort = 14;

    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 2,
        'xl' => 2,
    ];

    protected function getData(): array
    {
        // Determine the date range: respect page filters if provided, otherwise last 14 days
        $start = !empty($this->pageFilters['startDate']) ? Carbon::parse($this->pageFilters['startDate'])->startOfDay() : now()->copy()->subDays(13)->startOfDay();
        $end = !empty($this->pageFilters['endDate']) ? Carbon::parse($this->pageFilters['endDate'])->endOfDay() : now()->endOfDay();

        // Build quiz-scoped filters for attempts (level/grade/creator)
        $level = $this->pageFilters['level'] ?? null;
        $grade = $this->pageFilters['grade'] ?? null;
        $creator = $this->pageFilters['creator'] ?? null;

        // Cache key depends on filters and date range
        $cacheKey = 'dashboard:reg_quiz_scores:' . $start->toDateString() . ':' . $end->toDateString()
            . ':' . ($level ?? 'n') . ':' . ($grade ?? 'n') . ':' . ($creator ?? 'n');

        // Use tagged cache when supported so we can flush dashboard-related caches easily.
        if (Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
            [$registrationsRows, $attemptsRows] = Cache::tags(['dashboard_charts'])->remember($cacheKey, 60, function () use ($start, $end, $level, $grade, $creator) {
                // Registrations grouped by day
                $registrationsRows = DB::table('users')
                    ->select(DB::raw("DATE(created_at) AS day"), DB::raw('COUNT(*) as cnt'))
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('day')
                    ->pluck('cnt', 'day')
                    ->toArray();

                // Quiz attempts grouped by day with avg score
                $attemptsQuery = DB::table('quiz_attempts')
                    ->select(DB::raw("DATE(quiz_attempts.created_at) as day"), DB::raw('COUNT(*) as attempts'), DB::raw('AVG(quiz_attempts.score) as avg_score'))
                    ->whereBetween('quiz_attempts.created_at', [$start, $end])
                    ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id');

                if ($level)
                    $attemptsQuery->where('quizzes.level_id', $level);
                if ($grade)
                    $attemptsQuery->where('quizzes.grade_id', $grade);
                if ($creator)
                    $attemptsQuery->where('quizzes.user_id', $creator);

                $attemptsRows = $attemptsQuery->groupBy('day')->orderBy('day')->get()
                    ->mapWithKeys(fn($r) => [$r->day => ['attempts' => (int) $r->attempts, 'avg' => (float) $r->avg_score]])
                    ->toArray();

                return [$registrationsRows, $attemptsRows];
            });
        } else {
            [$registrationsRows, $attemptsRows] = Cache::remember($cacheKey, 60, function () use ($start, $end, $level, $grade, $creator) {
                // Registrations grouped by day
                $registrationsRows = DB::table('users')
                    ->select(DB::raw("DATE(created_at) AS day"), DB::raw('COUNT(*) as cnt'))
                    ->whereBetween('created_at', [$start, $end])
                    ->groupBy('day')
                    ->pluck('cnt', 'day')
                    ->toArray();

                // Quiz attempts grouped by day with avg score
                $attemptsQuery = DB::table('quiz_attempts')
                    ->select(DB::raw("DATE(quiz_attempts.created_at) as day"), DB::raw('COUNT(*) as attempts'), DB::raw('AVG(quiz_attempts.score) as avg_score'))
                    ->whereBetween('quiz_attempts.created_at', [$start, $end])
                    ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id');

                if ($level)
                    $attemptsQuery->where('quizzes.level_id', $level);
                if ($grade)
                    $attemptsQuery->where('quizzes.grade_id', $grade);
                if ($creator)
                    $attemptsQuery->where('quizzes.user_id', $creator);

                $attemptsRows = $attemptsQuery->groupBy('day')->orderBy('day')->get()
                    ->mapWithKeys(fn($r) => [$r->day => ['attempts' => (int) $r->attempts, 'avg' => (float) $r->avg_score]])
                    ->toArray();

                return [$registrationsRows, $attemptsRows];
            });
        }

        // Build labels for each day in range and map results
        $labels = [];
        $registrations = [];
        $quizzesTaken = [];
        $avgScores = [];

        $period = new \DatePeriod(new \DateTime($start->toDateString()), new \DateInterval('P1D'), (new \DateTime($end->toDateString()))->modify('+1 day'));
        foreach ($period as $dt) {
            $day = $dt->format('Y-m-d');
            $labels[] = Carbon::parse($day)->format('M j');
            $registrations[] = (int) ($registrationsRows[$day] ?? 0);
            $quizzesTaken[] = (int) ($attemptsRows[$day]['attempts'] ?? 0);
            $avgScores[] = (float) ($attemptsRows[$day]['avg'] ?? 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Registrations',
                    'data' => $registrations,
                    'borderColor' => '#537bc4', // Slate Blue
                    'backgroundColor' => 'rgba(83,123,196,0.1)',
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Quizzes taken',
                    'data' => $quizzesTaken,
                    'borderColor' => '#891f21', // Burgundy
                    'backgroundColor' => 'rgba(137,31,33,0.1)',
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Avg score',
                    'data' => $avgScores,
                    'borderColor' => '#F9B82E', // Brand Yellow
                    'backgroundColor' => 'rgba(249,184,46,0.1)',
                    'yAxisID' => 'score',
                    'tension' => 0.4,
                ],
            ],
            'options' => [
                'scales' => [
                    'score' => [
                        'type' => 'linear',
                        'position' => 'right',
                    ],
                ],
            ],
        ];
    }
}
