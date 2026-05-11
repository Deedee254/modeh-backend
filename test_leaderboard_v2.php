<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$timeframe = 'weekly';
$sortBy = 'average_score';
$sortDir = 'desc';
$startDate = now()->startOfWeek();

$applyConstraints = function($sub) use ($startDate) {
    if ($startDate) {
        $sub->where('created_at', '>=', $startDate);
    }
};

$query = \App\Models\User::query()->where('role', 'quizee');

if ($startDate) {
    // FIX: Select first, then withSum aliased as points
    $query->select(['id', 'name', 'email', 'social_avatar', 'avatar_url', 'created_at', 'role']);
    $query->withSum(['quizAttempts as points' => $applyConstraints], 'points_earned');
}

$query->withAvg(['quizAttempts as average_score' => $applyConstraints], 'score');
$query->withCount(['quizAttempts as attempts_count' => $applyConstraints]);

if ($sortBy === 'average_score') {
    $query->whereHas('quizAttempts', $applyConstraints)
          ->orderByRaw("average_score {$sortDir}")
          ->orderBy('attempts_count', 'desc');
}

echo "SQL: " . $query->toSql() . "\n";

$results = $query->get();
echo "Count: " . $results->count() . "\n";
foreach ($results as $u) {
    echo " - " . $u->name . " | Avg: " . $u->average_score . " | Points: " . $u->points . "\n";
}
