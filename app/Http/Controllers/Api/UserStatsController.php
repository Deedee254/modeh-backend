<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizeeLevel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserStatsController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $points = (int)($user->points ?? 0);
        
        // Get current level and next level
        $currentLevel = QuizeeLevel::getLevel($points);
        $nextLevel = QuizeeLevel::getNextLevel($points);
        
        // Calculate progress to next level
        $levelProgress = 0;
        if ($currentLevel && $nextLevel) {
            $levelRange = $nextLevel->min_points - $currentLevel->min_points;
            $userProgress = $points - $currentLevel->min_points;
            $levelProgress = ($userProgress / $levelRange) * 100;
        }
        
        // Get global rank (cached for 5 minutes)
        $globalRank = (int)Cache::remember("user_{$user->id}_rank", 300, function () use ($points) {
            return User::where('points', '>', $points)->count() + 1;
        });
        
        // Get institution rank if user has an institution
        $institutionRank = null;
        if ($user->institution_id) {
            $institutionRank = (int)Cache::remember(
                "user_{$user->id}_institution_rank",
                300,
                function () use ($user, $points) {
                    return User::where('institution_id', $user->institution_id)
                        ->where('points', '>', $points)
                        ->count() + 1;
                }
            );
        }

        // Get recent activity (last 10 quiz attempts with points earned)
        $activity = [];
        try {
            $quizAttempts = DB::table('quiz_attempts')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'quiz_id', 'points_earned', 'created_at', 'score']);

            foreach ($quizAttempts as $attempt) {
                // Try to get quiz title for better description
                $quizTitle = 'Quiz';
                try {
                    $quiz = DB::table('quizzes')->where('id', $attempt->quiz_id)->first(['title']);
                    if ($quiz) {
                        $quizTitle = $quiz->title;
                    }
                } catch (\Throwable $_) {}

                $activity[] = [
                    'id' => $attempt->id,
                    'description' => "Quiz completed: {$quizTitle} ({$attempt->score}%)",
                    'created_at' => $attempt->created_at,
                    'points' => (int)($attempt->points_earned ?? 0),
                    'quiz_id' => $attempt->quiz_id,
                    'score' => $attempt->score,
                ];
            }
        } catch (\Throwable $_) {
            // If quiz_attempts table doesn't exist or query fails, continue with empty activity
        }

        // Calculate metadata
        $totalQuizzes = 0;
        $averageScore = 0;
        $lastActivity = null;
        $streak = 0;
        $bestStreak = 0;

        try {
            // Total quizzes taken
            $totalQuizzes = DB::table('quiz_attempts')
                ->where('user_id', $user->id)
                ->count();

            // Average score
            if ($totalQuizzes > 0) {
                $avgResult = DB::table('quiz_attempts')
                    ->where('user_id', $user->id)
                    ->where('score', '>', 0)
                    ->avg('score');
                $averageScore = round($avgResult ?? 0, 2);
            }

            // Last activity timestamp
            $lastAttempt = DB::table('quiz_attempts')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->first(['created_at']);
            $lastActivity = $lastAttempt?->created_at;

        } catch (\Throwable $_) {
            // Ignore if tables don't exist
        }

        // Get unlocked achievements count
        $unlockedAchievements = 0;
        $totalAchievements = 0;
        try {
            if (method_exists($user, 'achievements')) {
                $unlockedAchievements = $user->achievements()->count();
                $totalAchievements = DB::table('achievements')->count();
            }
        } catch (\Throwable $_) {}

        return response()->json([
            'points' => (int)$points,
            'level' => $currentLevel ? [
                'name' => $currentLevel->name,
                'icon' => $currentLevel->icon,
                'description' => $currentLevel->description,
                'color_scheme' => $currentLevel->color_scheme,
                'progress' => (float)round($levelProgress, 1),
                'min_points' => (int)$currentLevel->min_points,
                'max_points' => (int)$currentLevel->max_points,
            ] : null,
            'next_level' => $nextLevel ? [
                'name' => $nextLevel->name,
                'points_needed' => (int)($nextLevel->min_points - $points),
                'min_points' => (int)$nextLevel->min_points,
            ] : null,
            'ranks' => [
                'global' => (int)$globalRank,
                'institution' => $institutionRank ? (int)$institutionRank : null,
            ],
            'streak' => (int)$streak,
            'best_streak' => (int)$bestStreak,
            'total_quizzes_taken' => (int)$totalQuizzes,
            'average_score' => (float)$averageScore,
            'last_activity' => $lastActivity,
            'unlocked_achievements' => (int)$unlockedAchievements,
            'total_achievements' => (int)$totalAchievements,
            'activity' => $activity,
        ]);
    }
}