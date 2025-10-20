<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizeeLevel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserStatsController extends Controller
{
    public function stats(Request $request)
    {
        $user = $request->user();
        $points = $user->points ?? 0;
        
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
        $globalRank = Cache::remember("user_{$user->id}_rank", 300, function () use ($points) {
            return User::where('points', '>', $points)->count() + 1;
        });
        
        // Get institution rank if user has an institution
        $institutionRank = null;
        if ($user->institution_id) {
            $institutionRank = Cache::remember(
                "user_{$user->id}_institution_rank",
                300,
                function () use ($user, $points) {
                    return User::where('institution_id', $user->institution_id)
                        ->where('points', '>', $points)
                        ->count() + 1;
                }
            );
        }

        return response()->json([
            'points' => $points,
            'level' => $currentLevel ? [
                'name' => $currentLevel->name,
                'icon' => $currentLevel->icon,
                'description' => $currentLevel->description,
                'color_scheme' => $currentLevel->color_scheme,
                'progress' => round($levelProgress, 1),
                'min_points' => $currentLevel->min_points,
                'max_points' => $currentLevel->max_points,
            ] : null,
            'next_level' => $nextLevel ? [
                'name' => $nextLevel->name,
                'points_needed' => $nextLevel->min_points - $points,
                'min_points' => $nextLevel->min_points,
            ] : null,
            'ranks' => [
                'global' => $globalRank,
                'institution' => $institutionRank,
            ],
        ]);
    }
}