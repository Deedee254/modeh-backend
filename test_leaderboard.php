<?php

/**
 * Hall of Fame Testing Utility
 * Run via: php artisan tinker test_leaderboard.php
 */

use App\Models\User;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// --- CONFIGURATION ---
$slug = 'criminal-litigation-capital-charges'; // Change to any quiz slug
$timeframe = 'all-time'; // Options: daily, weekly, monthly, custom, all-time
$customDate = null; // '2024-05-11' - Use when timeframe is 'custom'
$sortBy = 'points'; // Options: points, average_score

// --- RESOLUTION ---
$quiz = Quiz::where('slug', $slug)->first();
if (!$quiz) {
    dump("ERROR: Quiz not found for slug: $slug");
    return;
}
$quizId = $quiz->id;

$startDate = null;
$endDate = null;

if ($timeframe === 'custom' && $customDate) {
    $startDate = Carbon::parse($customDate)->startOfDay();
    $endDate = Carbon::parse($customDate)->endOfDay();
} elseif ($timeframe === 'daily') {
    $startDate = now()->startOfDay();
} elseif ($timeframe === 'weekly') {
    $startDate = now()->startOfWeek();
} elseif ($timeframe === 'monthly') {
    $startDate = now()->startOfMonth();
}

$applyConstraints = function($sub) use ($startDate, $endDate, $quizId) {
    if ($startDate && $endDate) {
        $sub->whereBetween('created_at', [$startDate, $endDate]);
    } elseif ($startDate) {
        $sub->where('created_at', '>=', $startDate);
    }
    if ($quizId) {
        $sub->where('quiz_id', $quizId);
    }
};

// --- QUERY BUILDING ---
$query = User::query()->where('role', 'quizee');

// Metrics for specific quiz
$statsSub = DB::table('quiz_attempts')
    ->select('user_id')
    ->selectRaw('MAX(score) as points')
    ->selectRaw('AVG(score) as average_score')
    ->selectRaw('COUNT(*) as attempts_count')
    ->where('quiz_id', $quizId)
    ->whereNotNull('score')
    ->groupBy('user_id');

if ($startDate && $endDate) {
    $statsSub->whereBetween('created_at', [$startDate, $endDate]);
} elseif ($startDate) {
    $statsSub->where('created_at', '>=', $startDate);
}

$bestTimesSub = DB::table('quiz_attempts')
    ->select('user_id', 'score', DB::raw('MIN(total_time_seconds) as min_time'))
    ->where('quiz_id', $quizId)
    ->whereNotNull('score')
    ->groupBy('user_id', 'score');

if ($startDate && $endDate) {
    $bestTimesSub->whereBetween('created_at', [$startDate, $endDate]);
} elseif ($startDate) {
    $bestTimesSub->where('created_at', '>=', $startDate);
}

$query->joinSub($statsSub, 'stats', 'users.id', '=', 'stats.user_id')
      ->joinSub($bestTimesSub, 'best_times', function ($join) {
          $join->on('users.id', '=', 'best_times.user_id')
               ->on('stats.points', '=', 'best_times.score');
      })
      ->select([
          'users.id', 'users.name', 'stats.points', 'stats.average_score', 
          'stats.attempts_count', 'best_times.min_time as best_time'
      ]);

// Sorting
if ($sortBy === 'points') {
    $query->orderByRaw("points DESC")
          ->orderByRaw("CASE WHEN best_time IS NULL THEN 2147483647 ELSE best_time END ASC")
          ->orderBy('name', 'asc');
} else {
    $query->orderByRaw("average_score DESC")
          ->orderBy('attempts_count', 'desc');
}

// --- OUTPUT ---
$results = $query->get();

dump("========================================");
dump("HALL OF FAME TEST RESULTS");
dump("========================================");
dump("Quiz: " . $quiz->title);
dump("Timeframe: " . $timeframe . ($customDate ? " ($customDate)" : ""));
dump("Sort By: " . $sortBy);
dump("Found: " . $results->count() . " players");
dump("----------------------------------------");

$results->each(fn($u) => dump([
    'rank' => '?',
    'name' => $u->name,
    'score' => (float)$u->points,
    'avg' => round((float)$u->average_score, 1) . '%',
    'attempts' => $u->attempts_count,
    'time' => $u->best_time . 's'
]));
dump("========================================");
