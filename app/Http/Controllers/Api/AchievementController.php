<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function progress(Request $request)
    {
        $user = $request->user();
        
        // Get all achievements with their criteria
        $achievements = Achievement::all();
        
        // Get user's unlocked achievements with completion dates
        $userAchievements = $user->achievements()
            ->withPivot('completed_at', 'progress')
            ->get()
            ->keyBy('id');

        // Format achievements with progress information
        $formattedAchievements = $achievements->map(function ($achievement) use ($userAchievements) {
            $userAchievement = $userAchievements->get($achievement->id);
            
            return [
                'id' => $achievement->id,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'points' => $achievement->points,
                'type' => $achievement->type,
                'criteria_value' => $achievement->criteria_value,
                'unlocked' => $userAchievement ? true : false,
                'progress' => $userAchievement ? $userAchievement->pivot->progress : 0,
                'completed_at' => $userAchievement ? $userAchievement->pivot->completed_at : null,
                'slug' => $achievement->slug
            ];
        });

        return response()->json([
            'achievements' => $formattedAchievements,
            'stats' => [
                'total_achievements' => $achievements->count(),
                'unlocked_achievements' => $userAchievements->count(),
                'total_points' => $userAchievements->sum('points')
            ]
        ]);
    }
}