<?php

namespace App\Services;

use App\Models\DailyChallengeCache;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Question;
use Exception;

class DailyChallengeBaker
{
    /**
     * Get today's cache for a level/grade combination, or create new one if it doesn't exist
     */
    public function getOrBakeForGrade(Grade $grade, ?Level $level = null, ?string $date = null): DailyChallengeCache
    {
        $date ??= now()->toDateString();
        $level ??= $grade->level;

        if (!$level) {
            throw new Exception("Grade must have an associated level");
        }

        $cache = DailyChallengeCache::where('level_id', $level->id)
            ->where('grade_id', $grade->id)
            ->where('date', $date)
            ->first();

        if ($cache) {
            return $cache;
        }

        // Bake new: 5 random questions from grade
        $questionIds = Question::where('grade_id', $grade->id)
            ->inRandomOrder()
            ->limit(5)
            ->pluck('id')
            ->toArray();

        // Validate we have at least 5 questions
        if (count($questionIds) < 5) {
            \Log::error("Cannot create daily challenge: only " . count($questionIds) . " questions available for grade {$grade->id} (level {$level->id})");
            throw new Exception("Insufficient questions available for this grade. Need at least 5 questions.");
        }

        return DailyChallengeCache::create([
            'date' => $date,
            'level_id' => $level->id,
            'grade_id' => $grade->id,
            'questions' => $questionIds,
            'is_active' => true,
        ]);
    }

    /**
     * Get questions for a cache
     */
    public function getQuestionsForCache(DailyChallengeCache $cache): array
    {
        return Question::whereIn('id', $cache->questions)
            ->get()
            ->toArray();
    }
}