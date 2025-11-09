<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        // Ensure user is a quiz master
        $user = $request->user();
        if (!$user || !$user->isQuizMaster()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get quizzes owned by this quiz master
        $quizzes = Quiz::where('user_id', $user->id)->pluck('id');
        if ($quizzes->isEmpty()) {
            return response()->json([
                'stats' => [
                    'totalQuizzes' => 0,
                    'totalAttempts' => 0,
                    'avgScore' => 0,
                    'completionRate' => 0
                ],
                'series' => [],
                'distribution' => $this->emptyDistribution(),
                'top_quizzes' => []
            ]);
        }

        // Basic stats
        $totalQuizzes = $quizzes->count();
        $attempts = QuizAttempt::whereIn('quiz_id', $quizzes);
        $totalAttempts = $attempts->count();
        $avgScore = round($attempts->whereNotNull('score')->avg('score') ?? 0, 1);
        $completions = $attempts->whereNotNull('score')->count();
        $completionRate = $totalAttempts > 0 
            ? round(($completions / $totalAttempts) * 100, 1) 
            : 0;

        // Trend data - compare to previous period
        $now = Carbon::now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $sixtyDaysAgo = $now->copy()->subDays(60);

        // Current period stats
        $currentAttempts = $attempts->where('created_at', '>=', $thirtyDaysAgo)->count();
        $currentAvgScore = round($attempts->where('created_at', '>=', $thirtyDaysAgo)
            ->whereNotNull('score')
            ->avg('score') ?? 0, 1);
        $currentCompletions = $attempts->where('created_at', '>=', $thirtyDaysAgo)
            ->whereNotNull('score')
            ->count();
        $currentTotal = $attempts->where('created_at', '>=', $thirtyDaysAgo)->count();
        $currentCompletionRate = $currentTotal > 0 
            ? round(($currentCompletions / $currentTotal) * 100, 1) 
            : 0;

        // Previous period stats
        $previousAttempts = $attempts->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])->count();
        $previousAvgScore = round($attempts->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])
            ->whereNotNull('score')
            ->avg('score') ?? 0, 1);
        $previousCompletions = $attempts->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])
            ->whereNotNull('score')
            ->count();
        $previousTotal = $attempts->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])->count();
        $previousCompletionRate = $previousTotal > 0 
            ? round(($previousCompletions / $previousTotal) * 100, 1) 
            : 0;

        // Calculate trends (percentage change)
        $attemptsTrend = $previousAttempts > 0 
            ? round((($currentAttempts - $previousAttempts) / $previousAttempts) * 100, 1) 
            : null;
        $scoreTrend = $previousAvgScore > 0 
            ? round((($currentAvgScore - $previousAvgScore) / $previousAvgScore) * 100, 1) 
            : null;
        $completionTrend = $previousCompletionRate > 0 
            ? round((($currentCompletionRate - $previousCompletionRate) / $previousCompletionRate) * 100, 1) 
            : null;
        
        // Attempts series (last 30 days)
        $series = $this->getAttemptsSeries($quizzes);

        // Score distribution
        $distribution = $this->getScoreDistribution($quizzes);

        // Top quizzes by attempts
        $topQuizzes = Quiz::whereIn('id', $quizzes)
            ->withCount('attempts')
            ->withAvg('attempts as avg_score', 'score')
            ->orderByDesc('attempts_count')
            ->limit(5)
            ->get()
            ->map(function ($quiz) {
                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'attempts' => $quiz->attempts_count,
                    'score' => round($quiz->avg_score ?? 0, 1)
                ];
            });

        return response()->json([
            'stats' => [
                'totalQuizzes' => $totalQuizzes,
                'totalAttempts' => $totalAttempts,
                'avgScore' => $avgScore,
                'completionRate' => $completionRate,
                'totalAttemptsTrend' => $attemptsTrend,
                'avgScoreTrend' => $scoreTrend,
                'completionRateTrend' => $completionTrend
            ],
            'series' => $series,
            'distribution' => $distribution,
            'top_quizzes' => $topQuizzes
        ]);
    }

    private function getAttemptsSeries($quizIds)
    {
        $startDate = now()->subDays(29)->startOfDay();
        
        // Get daily counts
        $dailyCounts = DB::table('quiz_attempts')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->whereIn('quiz_id', $quizIds)
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        // Fill in missing dates with zeros
        $series = [];
        for ($i = 0; $i < 30; $i++) {
            $date = $startDate->copy()->addDays($i)->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'value' => $dailyCounts[$date] ?? 0
            ];
        }

        return $series;
    }

    private function getScoreDistribution($quizIds)
    {
        $scores = QuizAttempt::whereIn('quiz_id', $quizIds)
            ->whereNotNull('score')
            ->select('score')
            ->get();

        $distribution = array_fill(0, 10, 0);
        foreach ($scores as $attempt) {
            $score = (int)round($attempt->score);
            $bucket = min(9, (int)floor($score / 10));
            $distribution[$bucket]++;
        }

        // Convert to frontend format with labels and colors
        $colors = [
            '#EF4444', // red-500    0-9%
            '#F97316', // orange-500  10-19%
            '#F59E0B', // amber-500   20-29%
            '#EAB308', // yellow-500  30-39%
            '#84CC16', // lime-500    40-49%
            '#22C55E', // green-500   50-59%
            '#10B981', // emerald-500 60-69%
            '#14B8A6', // teal-500    70-79%
            '#06B6D4', // cyan-500    80-89%
            '#0EA5E9', // sky-500     90-100%
        ];

        return array_map(function ($count, $index) use ($colors) {
            return [
                'label' => sprintf('%d-%d%%', $index * 10, ($index * 10) + 9),
                'value' => $count,
                'color' => $colors[$index]
            ];
        }, $distribution, array_keys($distribution));
    }

    private function emptyDistribution()
    {
        $colors = [
            '#EF4444', '#F97316', '#F59E0B', '#EAB308', '#84CC16',
            '#22C55E', '#10B981', '#14B8A6', '#06B6D4', '#0EA5E9'
        ];

        return array_map(function ($index) use ($colors) {
            return [
                'label' => sprintf('%d-%d%%', $index * 10, ($index * 10) + 9),
                'value' => 0,
                'color' => $colors[$index]
            ];
        }, range(0, 9));
    }
}