<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use App\Events\AchievementUnlocked;

class AchievementService
{
    /**
     * Check and award streak-based achievements
     */
    public function checkStreakAchievements(User $user, int $currentStreak, ?int $attemptId = null): void
    {
        $streakAchievements = Achievement::where('type', 'streak')
            ->where('criteria_value', '<=', $currentStreak)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        foreach ($streakAchievements as $achievement) {
            $this->awardAchievement($user, $achievement, $attemptId);
        }
    }

    /**
     * Check and award completion-based achievements
     */
    public function checkCompletionAchievements(User $user, float $completionRate, ?int $attemptId = null): void
    {
        $completionAchievements = Achievement::where('type', 'completion')
            ->where('criteria_value', '<=', $completionRate)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        foreach ($completionAchievements as $achievement) {
            $this->awardAchievement($user, $achievement, $attemptId);
        }
    }

    /**
     * Check and award score-based achievements
     */
    public function checkScoreAchievements(User $user, float $score, ?int $attemptId = null): void
    {
        $scoreAchievements = Achievement::where('type', 'score')
            ->where('criteria_value', '<=', $score)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        foreach ($scoreAchievements as $achievement) {
            $this->awardAchievement($user, $achievement, $attemptId);
        }
    }

    /**
     * Award an achievement to a user
     */
    /**
     * Award an achievement to a user. Optionally associate it with a quiz attempt.
     *
     * @param User $user
     * @param Achievement $achievement
     * @param int|null $attemptId
     */
    protected function awardAchievement(User $user, Achievement $achievement, ?int $attemptId = null): void
    {
        $payload = [
            'completed_at' => now(),
            'progress' => $achievement->criteria_value,
        ];
        if ($attemptId) {
            $payload['attempt_id'] = $attemptId;
        }

        $user->achievements()->attach($achievement->id, $payload);

        // Add points from achievement
        $user->increment('points', $achievement->points);

        // Broadcast achievement
        event(new AchievementUnlocked($user, $achievement));
    }
}