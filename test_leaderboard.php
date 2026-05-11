<?php

use App\Models\User;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

// Mock some parameters
$quiz = Quiz::first();
if (!$quiz) {
    echo "No quizzes found\n";
    exit;
}

$quizId = $quiz->id;
$timeframe = 'all-time';
$startDate = null;
$topicId = null;
$subjectId = null;

$applyConstraints = function($sub) use ($startDate, $quizId, $topicId, $subjectId) {
    if ($startDate) {
        $sub->where('created_at', '>=', $startDate);
    }
    if ($quizId) {
        $sub->where('quiz_id', $quizId);
    }
    if ($topicId || $subjectId) {
        $sub->whereHas('quiz', function($q) use ($topicId, $subjectId) {
            if ($topicId) $q->where('topic_id', $topicId);
            if ($subjectId) $q->where('subject_id', $subjectId);
        });
    }
};

$query = User::query()->where('role', 'quizee');

if ($quizId) {
    $bestScoresSub = DB::table('quiz_attempts')
        ->select('user_id', DB::raw('MAX(score) as max_score'))
        ->where('quiz_id', $quizId)
        ->whereNotNull('score')
        ->groupBy('user_id');

    $bestTimesSub = DB::table('quiz_attempts')
        ->select('user_id', 'score', DB::raw('MIN(total_time_seconds) as min_time'))
        ->where('quiz_id', $quizId)
        ->whereNotNull('score')
        ->groupBy('user_id', 'score');

    $query->joinSub($bestScoresSub, 'best_scores', 'users.id', '=', 'best_scores.user_id')
          ->joinSub($bestTimesSub, 'best_times', function ($join) {
              $join->on('users.id', '=', 'best_times.user_id')
                   ->on('best_scores.max_score', '=', 'best_times.score');
          })
          ->select(['users.*'])
          ->selectRaw('best_scores.max_score as points')
          ->selectRaw('best_times.min_time as best_time');
}

$query->withAvg(['quizAttempts as average_score' => $applyConstraints], 'score');
$query->withCount(['quizAttempts as attempts_count' => $applyConstraints]);

$results = $query->get();

echo "Results for Quiz ID: $quizId (" . $quiz->title . ")\n";
echo "Count: " . $results->count() . "\n";
foreach ($results as $user) {
    echo "User: " . $user->name . " | Points: " . $user->points . " | Avg: " . $user->average_score . " | Time: " . $user->best_time . "\n";
}
