<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Events\AchievementUnlocked;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    /**
     * Check and award streak-based achievements
     */
    public function checkStreakAchievements(User $user, int $currentStreak, ?int $attemptId = null): array
    {
        $streakAchievements = Achievement::where('type', 'streak')
            ->where('criteria_value', '<=', $currentStreak)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        $awarded = [];
        foreach ($streakAchievements as $achievement) {
            try {
                $a = $this->awardAchievement($user, $achievement, $attemptId);
                if ($a) $awarded[] = $a;
            } catch (\Throwable $e) {
                try { \Log::warning('Failed to award streak achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
            }
        }

        return $awarded;
    }

    /**
     * Check and award completion-based achievements
     */
    public function checkCompletionAchievements(User $user, float $completionRate, ?int $attemptId = null): array
    {
        $completionAchievements = Achievement::where('type', 'completion')
            ->where('criteria_value', '<=', $completionRate)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        $awarded = [];
        foreach ($completionAchievements as $achievement) {
            try {
                $a = $this->awardAchievement($user, $achievement, $attemptId);
                if ($a) $awarded[] = $a;
            } catch (\Throwable $e) {
                try { \Log::warning('Failed to award completion achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
            }
        }

        return $awarded;
    }

    /**
     * Check and award score-based achievements
     */
    public function checkScoreAchievements(User $user, float $score, ?int $attemptId = null): array
    {
        $scoreAchievements = Achievement::where('type', 'score')
            ->where('criteria_value', '<=', $score)
            ->whereDoesntHave('users', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->get();

        $awarded = [];
        foreach ($scoreAchievements as $achievement) {
            try {
                $a = $this->awardAchievement($user, $achievement, $attemptId);
                if ($a) $awarded[] = $a;
            } catch (\Throwable $e) {
                try { \Log::warning('Failed to award score achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
            }
        }

        return $awarded;
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
    protected function awardAchievement(User $user, Achievement $achievement, ?int $attemptId = null): Achievement
    {

        $payload = [
            'completed_at' => now(),
            'progress' => $achievement->criteria_value,
        ];
        if ($attemptId) {
            $payload['attempt_id'] = $attemptId;
        }

    $user->achievements()->attach($achievement->id, $payload);

        // Add points from achievement only for quizee users. Quiz-masters use wallet earnings
        // and shouldn't have their leaderboard points changed here.
        try {
            if (isset($user->role) && $user->role === 'quizee') {
                $user->increment('points', $achievement->points);
            } else {
                try { \Log::info("Achievement awarded to non-quizee (no points increment): user={$user->id}, role={$user->role}, achievement={$achievement->id}"); } catch (\Throwable $_) {}
            }
        } catch (\Throwable $e) {
            // Log and continue; we don't want achievements to fail due to points column issues
            try { \Log::warning('Could not increment user points for achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
        }

        // Broadcast achievement
        event(new AchievementUnlocked($user, $achievement));

        // Return the awarded achievement for callers to inspect
        return $achievement;
    }

    /**
     * Generic achievements checker used by battles, tournaments, and daily challenges.
     * Accepts either a User instance or a user id and a payload with keys like 'type', 'score', 'total'.
     * This finds achievements that match the given type and criteria and awards them.
     *
     * @param User|int $userOrId
     * @param array $payload
     * @return array
     */
    public function checkAchievements($userOrId, array $payload): array
    {
        $user = $userOrId instanceof User ? $userOrId : User::find($userOrId);
        if (!$user) return [];

        $awarded = [];

        // First check standard achievement types
        $standardAwarded = $this->checkStandardAchievements($user, $payload);
        $awarded = array_merge($awarded, $standardAwarded);

        // Check time-based achievements
        if (isset($payload['time']) && isset($payload['score']) && isset($payload['question_count'])) {
            $timeAwarded = $this->checkTimeAchievements($user, $payload['time'], $payload['score'], $payload['question_count'], $payload['attempt_id'] ?? null);
            $awarded = array_merge($awarded, $timeAwarded);
        }

        // Check subject-based achievements
        if (isset($payload['subject_id']) && isset($payload['score'])) {
            $subjectAwarded = $this->checkSubjectAchievements($user, $payload['subject_id'], $payload['score'], $payload['attempt_id'] ?? null);
            $awarded = array_merge($awarded, $subjectAwarded);
        }

        // Check improvement achievements
        if (isset($payload['score']) && isset($payload['previous_score'])) {
            $improvementAwarded = $this->checkImprovementAchievements($user, $payload['score'], $payload['previous_score'], $payload['attempt_id'] ?? null);
            $awarded = array_merge($awarded, $improvementAwarded);
        }

        // Weekend Warrior check
        if (Carbon::now()->isWeekend() && isset($payload['attempt_id'])) {
            $weekendAchievement = $this->checkWeekendWarrior($user, $payload['attempt_id']);
            if ($weekendAchievement) {
                $awarded[] = $weekendAchievement;
            }
        }

        // Topic-based achievements
        if (isset($payload['quiz_id'])) {
            $quiz = Quiz::with('topic')->find($payload['quiz_id']);
            if ($quiz && $quiz->topic_id) {
                $topicAwards = $this->checkTopicAchievements(
                    $user,
                    $quiz->topic_id,
                    $payload['score'] ?? 0,
                    $payload['attempt_id'] ?? null
                );
                $awarded = array_merge($awarded, $topicAwards);
            }

            // Check specific quiz achievements
            $quizAwards = $this->checkQuizSpecificAchievements(
                $user,
                $quiz,
                $payload['score'] ?? 0,
                $payload['attempt_id'] ?? null
            );
            $awarded = array_merge($awarded, $quizAwards);
        }

        // Quiz-created achievements (level/course scoped)
        if (($payload['type'] ?? null) === 'quiz_created') {
            $createdAwards = $this->checkQuizCreatedAchievements($user, $payload);
            $awarded = array_merge($awarded, $createdAwards);
        }

        return $awarded;
    }

    /**
     * Check achievements related to quiz creation that may be scoped to a level or course.
     * Expects payload to include 'quiz_id' and may include 'level_id' and 'grade_id'.
     */
    private function checkQuizCreatedAchievements(User $user, array $payload): array
    {
        $awarded = [];

        $achievements = Achievement::where('type', 'quiz_created')
            ->whereDoesntHave('users', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })->get();

        if (!$achievements || $achievements->isEmpty()) return [];

        // Determine context from payload
        $levelId = $payload['level_id'] ?? null;
        $gradeId = $payload['grade_id'] ?? null;

        foreach ($achievements as $ach) {
            try {
                $criteria = $ach->criteria ?? null; // cast to array by model
                // support either JSON string or array
                if (is_string($criteria)) {
                    $criteria = json_decode($criteria, true) ?: [];
                }
                if (!is_array($criteria)) $criteria = [];

                $requiredCount = $criteria['count'] ?? ($ach->criteria_value ?? 1);

                // If achievement requires a course (tertiary), ensure the quiz points to a grade of type 'course'
                if (!empty($criteria['require_course'])) {
                    // If gradeId isn't provided, try to infer via level+quiz_id
                    if ($gradeId) {
                        $g = \App\Models\Grade::find($gradeId);
                        if (!($g && ($g->type ?? null) === 'course')) continue; // not a course quiz
                    } else if (!empty($payload['quiz_id'])) {
                        $q = Quiz::find($payload['quiz_id']);
                        if (!$q || !$q->grade_id) continue;
                        $g = \App\Models\Grade::find($q->grade_id);
                        if (!($g && ($g->type ?? null) === 'course')) continue;
                        // set gradeId for counting below
                        $gradeId = $q->grade_id;
                    } else {
                        continue; // can't validate course requirement without grade or quiz
                    }
                }

                // Determine counting scope: prefer grade if criteria has 'per_grade' true, else level if levelId available, else global per-user quizzes
                $scope = 'user';
                if (!empty($criteria['per_grade']) && $gradeId) $scope = 'grade';
                elseif ($levelId) $scope = 'level';

                $count = 0;
                if ($scope === 'grade' && $gradeId) {
                    $count = Quiz::where('user_id', $user->id)->where('grade_id', $gradeId)->count();
                } elseif ($scope === 'level' && $levelId) {
                    $count = Quiz::where('user_id', $user->id)->where('level_id', $levelId)->count();
                } else {
                    // fallback: count quizzes created by user (global)
                    $count = Quiz::where('user_id', $user->id)->count();
                }

                if ($count >= $requiredCount) {
                    $awarded[] = $this->awardAchievement($user, $ach, $payload['attempt_id'] ?? null);
                }
            } catch (\Throwable $e) {
                try { \Log::warning('Failed to evaluate quiz_created achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
            }
        }

        return $awarded;
    }

    /**
     * Check standard achievement types (streak, completion, score)
     */
    private function checkStandardAchievements(User $user, array $payload): array
    {
        $type = $payload['type'] ?? null;
        if (!$type) return [];

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

        $awarded = [];
        foreach ($achievements as $a) {
            $attemptId = $payload['attempt_id'] ?? null;
            try {
                $awardedAchievement = $this->awardAchievement($user, $a, $attemptId);
                if ($awardedAchievement) $awarded[] = $awardedAchievement;
            } catch (\Throwable $e) {
                try { \Log::warning('Failed to award achievement: '.$e->getMessage()); } catch (\Throwable $_) {}
            }
        }

        return $awarded;
    }

    /**
     * Check time-based achievements
     */
    private function checkTimeAchievements(User $user, int $time, float $score, int $questionCount, ?int $attemptId = null): array
    {
        $awarded = [];

        // Quick Thinker - under 5 minutes with 100% score
        if ($time <= 300 && $score == 100) {
            $achievement = Achievement::where('slug', 'quick-thinker')
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();
            if ($achievement) {
                $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
            }
        }

        // Marathon Runner - Long quizzes with good scores
        if ($questionCount >= 20 && $score >= 85) {
            $longQuizCount = QuizAttempt::where('user_id', $user->id)
                ->where('score', '>=', 85)
                ->whereHas('quiz', function ($query) {
                    $query->has('questions', '>=', 20);
                })
                ->count();

            if ($longQuizCount >= 3) {
                $achievement = Achievement::where('slug', 'marathon-runner')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }

        return $awarded;
    }

    /**
     * Check subject-based achievements
     */
    private function checkSubjectAchievements(User $user, int $subjectId, float $score, ?int $attemptId = null): array
    {
        $awarded = [];

        // Subject Expert - 5 quizzes above 90% in same subject
        $subjectHighScores = QuizAttempt::where('user_id', $user->id)
            ->where('score', '>=', 90)
            ->whereHas('quiz', function ($query) use ($subjectId) {
                $query->where('subject_id', $subjectId);
            })
            ->count();

        if ($subjectHighScores >= 5) {
            $achievement = Achievement::where('slug', 'subject-expert')
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();
            if ($achievement) {
                $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
            }
        }

        // All-Rounder - Above 80% in 5 different subjects
        if ($score >= 80) {
            $distinctSubjects = QuizAttempt::where('user_id', $user->id)
                ->where('score', '>=', 80)
                ->whereHas('quiz')
                ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
                ->select('quizzes.subject_id')
                ->distinct()
                ->count();

            if ($distinctSubjects >= 5) {
                $achievement = Achievement::where('slug', 'all-rounder')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }

        return $awarded;
    }

    /**
     * Check improvement achievements
     */
    private function checkImprovementAchievements(User $user, float $newScore, float $oldScore, ?int $attemptId = null): array
    {
        $awarded = [];

        // Calculate improvement percentage
        $improvement = (($newScore - $oldScore) / $oldScore) * 100;

        // Comeback King - 30% improvement
        if ($improvement >= 30) {
            $achievement = Achievement::where('slug', 'comeback-king')
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();
            if ($achievement) {
                $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
            }
        }

        return $awarded;
    }

    /**
     * Check weekend warrior achievement
     */
    private function checkWeekendWarrior(User $user, ?int $attemptId = null): ?Achievement
    {
        $now = Carbon::now();
        if ($now->isWeekend()) {
            $weekendAttempts = QuizAttempt::where('user_id', $user->id)
                ->where('score', '>=', 80)
                ->whereBetween('created_at', [
                    $now->copy()->startOfWeek()->addDays(5), // Friday
                    $now->copy()->endOfWeek()                // Sunday
                ])
                ->count();

            if ($weekendAttempts >= 5) {
                $achievement = Achievement::where('slug', 'weekend-warrior')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    return $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }
        return null;
    }

    /**
     * Check topic-based achievements
     */
    private function checkTopicAchievements(User $user, int $topicId, float $score, ?int $attemptId = null): array
    {
        $awarded = [];

        // Topic Master - Complete 10 quizzes with 85%+ score in the same topic
        $topicHighScores = QuizAttempt::where('user_id', $user->id)
            ->where('score', '>=', 85)
            ->whereHas('quiz', function ($query) use ($topicId) {
                $query->where('topic_id', $topicId);
            })
            ->count();

        if ($topicHighScores >= 10) {
            $achievement = Achievement::where('slug', 'topic-master')
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();
            if ($achievement) {
                $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
            }
        }

        // Topic Explorer - Score 90%+ in quizzes from 3 different topics
        if ($score >= 90) {
            $distinctTopics = QuizAttempt::where('user_id', $user->id)
                ->where('score', '>=', 90)
                ->whereHas('quiz')
                ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id')
                ->select('quizzes.topic_id')
                ->distinct()
                ->count();

            if ($distinctTopics >= 3) {
                $achievement = Achievement::where('slug', 'topic-explorer')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }

        return $awarded;
    }

    /**
     * Check achievements specific to individual quizzes
     */
    private function checkQuizSpecificAchievements(User $user, Quiz $quiz, float $score, ?int $attemptId = null): array
    {
        $awarded = [];

        // Perfect Score First Try - Get 100% on first attempt
        $previousAttempts = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->count();

        if ($previousAttempts === 1 && $score === 100) {
            $achievement = Achievement::where('slug', 'first-try-perfect')
                ->whereDoesntHave('users', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();
            if ($achievement) {
                $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
            }
        }

        // Quiz Champion - Get highest score in a quiz (minimum 5 participants)
        $participantCount = QuizAttempt::where('quiz_id', $quiz->id)
            ->distinct('user_id')
            ->count();

        if ($participantCount >= 5) {
            $maxScore = QuizAttempt::where('quiz_id', $quiz->id)
                ->max('score');

            if ($score >= $maxScore) {
                $achievement = Achievement::where('slug', 'quiz-champion')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }

        // Persistence Master - Complete the same quiz 5 times with improving scores
        $attempts = QuizAttempt::where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->orderBy('created_at')
            ->pluck('score')
            ->toArray();

        if (count($attempts) >= 5) {
            $improving = true;
            for ($i = 1; $i < count($attempts); $i++) {
                if ($attempts[$i] <= $attempts[$i - 1]) {
                    $improving = false;
                    break;
                }
            }

            if ($improving) {
                $achievement = Achievement::where('slug', 'persistence-master')
                    ->whereDoesntHave('users', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->first();
                if ($achievement) {
                    $awarded[] = $this->awardAchievement($user, $achievement, $attemptId);
                }
            }
        }

        return $awarded;
    }
}