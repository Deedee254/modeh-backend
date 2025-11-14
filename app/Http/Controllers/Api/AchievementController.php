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

        // Preload some user data we'll use to compute incremental progress
        $quizeeProfile = $user->quizeeProfile; // may be null for non-quizee users
        $attempts = $user->quizAttempts()->orderBy('created_at', 'desc')->get();
        $attemptCount = $attempts->count();
        $maxScore = $attempts->max('score') ?? 0;

        // Format achievements with progress information (compute best-effort progress when not unlocked)
        $formattedAchievements = $achievements->map(function ($achievement) use ($userAchievements, $quizeeProfile, $attempts, $attemptCount, $maxScore) {
            $userAchievement = $userAchievements->get($achievement->id);

            // If user already unlocked this achievement, prefer stored pivot progress
            if ($userAchievement) {
                $progress = $userAchievement->pivot->progress ?? 0;
                $unlocked = true;
            } else {
                $unlocked = false;
                $progress = 0;

                // Compute a best-effort progress number based on achievement type and criteria
                $type = $achievement->type ?? null;
                $criteria = is_array($achievement->criteria) ? $achievement->criteria : ($achievement->criteria ? (array) $achievement->criteria : []);
                $criteriaValue = $achievement->criteria_value ?? 0;

                switch ($type) {
                    case 'streak':
                        // Use quizee profile's current_streak when available
                        if ($quizeeProfile && isset($quizeeProfile->current_streak)) {
                            $progress = min((int)$quizeeProfile->current_streak, (int)$criteriaValue);
                        }
                        break;

                    case 'daily_challenge':
                    case 'daily_completion':
                        // Count completed daily challenges (relation on Quizee)
                        if ($quizeeProfile) {
                            try {
                                $progress = min($quizeeProfile->dailyChallenges()->count(), (int)$criteriaValue);
                            } catch (\Throwable $e) {
                                $progress = 0;
                            }
                        }
                        break;

                    case 'subject':
                    case 'topic':
                        // If achievement.criteria contains a subject_id/topic_id and quizee subject_progress has a value
                        $key = $criteria['subject_id'] ?? $criteria['topic_id'] ?? null;
                        if ($key && $quizeeProfile && is_array($quizeeProfile->subject_progress)) {
                            $entry = $quizeeProfile->subject_progress[$key] ?? null;
                            if (is_array($entry) && isset($entry['progress'])) {
                                // subject_progress.progress is stored as percent (0..100)
                                $entryProgress = (float) $entry['progress'];
                                $progress = (int) round(($entryProgress / 100) * (int)$criteriaValue);
                            }
                        }
                        break;

                    case 'completion':
                        // Count total quiz attempts (completion of quizzes)
                        $progress = min($attemptCount, (int)$criteriaValue);
                        break;

                    case 'score':
                        // Use user's best score seen in attempts as progress (capped to criteria_value)
                        $progress = min((int)round($maxScore), (int)$criteriaValue);
                        break;

                    case 'improvement':
                        // Improvement between last two attempts
                        if ($attemptCount >= 2) {
                            $latest = $attempts->first();
                            $second = $attempts->slice(1,1)->first();
                            if ($latest && $second && isset($latest->score) && isset($second->score)) {
                                $improv = max(0, $latest->score - $second->score);
                                $progress = min((int)round($improv), (int)$criteriaValue);
                            }
                        }
                        break;

                    default:
                        // For unknown types, leave progress at 0 (frontend will show 0%)
                        $progress = 0;
                        break;
                }
            }

            // Ensure progress is a non-negative integer and does not exceed criteria_value
            $criteriaValue = $achievement->criteria_value ?? 0;
            $progress = (int) max(0, min($progress, (int)$criteriaValue));

            return [
                'id' => $achievement->id,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'points' => $achievement->points,
                'type' => $achievement->type,
                'criteria_value' => $criteriaValue,
                'unlocked' => $unlocked,
                'progress' => $progress,
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