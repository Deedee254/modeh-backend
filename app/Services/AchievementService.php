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

        // Add points from achievement (defensive: wrap in try/catch to avoid failing when points column missing)
        try {
            $user->increment('points', $achievement->points);
        } catch (\Throwable $e) {
            // Log and continue; we don't want achievements to fail due to points column issues
            try { \Log::warning('Could not increment user points for achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
        }

        // Broadcast achievement
        event(new AchievementUnlocked($user, $achievement));
    }

    /**
     * Generic achievements checker used by battles, tournaments, and daily challenges.
     * Accepts either a User instance or a user id and a payload with keys like 'type', 'score', 'total'.
     * This finds achievements that match the given type and criteria and awards them.
     *
     * @param User|int $userOrId
     * @param array $payload
     * @return void
     */
    public function checkAchievements($userOrId, array $payload): void
    {
        $user = $userOrId instanceof User ? $userOrId : User::find($userOrId);
        if (!$user) return;

        $type = $payload['type'] ?? null;
        if (!$type) return;

        // Build a query for achievements matching this type and criteria
        $query = Achievement::where('type', $type);

        // If the payload contains a numeric score/total, use criteria_value comparisons
        if (isset($payload['score']) && is_numeric($payload['score'])) {
            $query->where('criteria_value', '<=', $payload['score']);
        } elseif (isset($payload['total']) && is_numeric($payload['total'])) {
            $query->where('criteria_value', '<=', $payload['total']);
        }

        $achievements = $query->whereDoesntHave('users', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->get();

        foreach ($achievements as $a) {
            // attempt_id may be present in payload
            $attemptId = $payload['attempt_id'] ?? null;
            $this->awardAchievement($user, $a, $attemptId);
        }
    }
}