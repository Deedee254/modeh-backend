<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeCache;
use App\Models\DailyChallengeSubmission;
use App\Models\Question;
use App\Models\UserDailyChallenge;
use App\Services\AchievementService;
use App\Services\DailyChallengeBaker;
use App\Services\QuestionMarkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DailyChallengeController extends Controller
{
    protected $achievementService;
    protected $dailyChallengeBaker;
    protected $markingService;

    public function __construct(
        AchievementService $achievementService,
        DailyChallengeBaker $dailyChallengeBaker,
        QuestionMarkingService $markingService
    ) {
        $this->achievementService = $achievementService;
        $this->dailyChallengeBaker = $dailyChallengeBaker;
        $this->markingService = $markingService;
        // Only require auth for non-public endpoints
        $this->middleware('auth:sanctum')->except(['leaderboard']);
    }

    /**
     * Get today's daily challenge
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $quizee = $user->quizee;

        if (!$quizee || !$quizee->grade || !$quizee->level) {
            return response()->json(['error' => 'User grade or level not found'], 400);
        }

        try {
            // Get or create today's cache for user's grade/level
            $cache = $this->dailyChallengeBaker->getOrBakeForGrade($quizee->grade, $quizee->level);

            // Get the questions
            $questions = Question::whereIn('id', $cache->questions)->get();

            // Check if user has already submitted today
            $existingSubmission = DailyChallengeSubmission::where('user_id', $user->id)
                ->where('daily_challenge_cache_id', $cache->id)
                ->first();

            return response()->json([
                'challenge' => [
                    'id' => $cache->id,
                    'title' => 'Daily Challenge - ' . $quizee->grade->name,
                    'description' => 'Answer 5 questions to complete today\'s challenge',
                    'date' => $cache->date,
                    'grade' => $quizee->grade,
                    'level' => $quizee->level,
                ],
                'questions' => $questions,
                'cache_id' => $cache->id,
                'completion' => $existingSubmission,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching daily challenge: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Get user's daily challenge history (last 100 days)
     */
    public function userHistory(Request $request)
    {
        $user = $request->user();

        $submissions = DailyChallengeSubmission::where('user_id', $user->id)
            ->with('cache')
            ->orderBy('completed_at', 'desc')
            ->limit(100)
            ->get()
            ->map(function ($submission) {
                return [
                    'id' => $submission->id,
                    'completed_at' => $submission->completed_at->toIso8601String(),
                    'score' => $submission->score,
                    'cache_id' => $submission->daily_challenge_cache_id,
                    'date' => $submission->cache?->date,
                ];
            });

        return response()->json([
            'data' => $submissions,
        ]);
    }

    /**
     * Get submission details with question explanations
     */
    public function getSubmissionDetails(Request $request, DailyChallengeSubmission $submission)
    {
        $user = $request->user();

        // Verify user owns this submission
        if ($submission->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get the cache and questions
        $cache = $submission->cache;
        $questions = Question::whereIn('id', $cache->questions)->get()->keyBy('id');

        // Build detailed results with explanations
        $results = collect($submission->is_correct)->map(function ($isCorrect, $questionId) use ($submission, $questions) {
            $question = $questions->get($questionId);
            if (!$question) return null;

            $userAnswer = $submission->answers[$questionId] ?? null;

            return [
                'question_id' => $questionId,
                'question_text' => $question->body,
                'question_type' => $question->type,
                'options' => $question->options,
                'user_answer' => $userAnswer,
                'correct_answers' => $question->answers,
                'is_correct' => $isCorrect,
                'explanation' => $question->explanation,
            ];
        })->filter();

        return response()->json([
            'submission' => [
                'id' => $submission->id,
                'cache_id' => $submission->daily_challenge_cache_id,
                'score' => $submission->score,
                'time_taken' => $submission->time_taken,
                'completed_at' => $submission->completed_at->toIso8601String(),
            ],
            'results' => $results->values(),
            'cache_date' => $cache->date,
        ]);
    }

    /**
     * Submit a daily challenge attempt
     */
    public function submit(Request $request)
    {
        $user = $request->user();
        $quizee = $user->quizee;

        if (!$quizee || !$quizee->grade || !$quizee->level) {
            return response()->json(['error' => 'User grade or level not found'], 400);
        }

        // Validate request
        $validated = $request->validate([
            'cache_id' => 'required|integer|exists:daily_challenges_cache,id',
            'answers' => 'required|array',
            'time_taken' => 'nullable|integer|min:0',
        ]);

        // Get cache and verify it belongs to user's grade/level (security check)
        $cache = DailyChallengeCache::where('id', $validated['cache_id'])
            ->where('level_id', $quizee->level->id)
            ->where('grade_id', $quizee->grade->id)
            ->firstOrFail();

        // Check if user already submitted for this cache
        $existingSubmission = DailyChallengeSubmission::where('user_id', $user->id)
            ->where('daily_challenge_cache_id', $cache->id)
            ->first();

        if ($existingSubmission) {
            return response()->json(['error' => 'Already submitted for today'], 409);
        }

        // Get questions and calculate score server-side using shared marking service
        $questions = Question::whereIn('id', $cache->questions)->get();
        $markingResult = $this->markingService->calculateScore($validated['answers'], $questions, false);

        // Create submission record
        $submission = DailyChallengeSubmission::create([
            'user_id' => $user->id,
            'daily_challenge_cache_id' => $cache->id,
            'answers' => $validated['answers'],
            'score' => $markingResult['score'],
            'is_correct' => array_column($markingResult['results'], 'correct', 'question_id'),
            'time_taken' => $validated['time_taken'] ?? null,
            'completed_at' => now(),
        ]);

        // Calculate streak
        $streak = $this->calculateStreak($user);

        // Check achievements: daily challenge completion + streak bonuses
        $awarded = [];
        
        // Check general daily challenge completion achievements
        $completionAwards = $this->achievementService->checkAchievements($user->id, [
            'type' => 'daily_challenge_completed',
            'score' => $markingResult['score'],
        ]);
        $awarded = array_merge($awarded, $completionAwards);

        // Check streak-based achievements (3-day, 5-day, 7-day, etc.)
        $streakAwards = $this->achievementService->checkStreakAchievements($user, $streak);
        $awarded = array_merge($awarded, $streakAwards);

        // Refresh user to include newly awarded achievements/points
        $user = $user->fresh()->load('achievements');

        return response()->json([
            'score' => $markingResult['score'],
            'streak' => $streak,
            'awarded_achievements' => $awarded,
            'user' => $user,
            'submission' => $submission,
        ]);
    }

    /**
     * Get daily challenge leaderboard
     */
    public function leaderboard(Request $request)
    {
        try {
            $query = DailyChallengeSubmission::query()
                ->select(
                    'daily_challenge_submissions.id',
                    'daily_challenge_submissions.user_id',
                    'daily_challenge_submissions.score',
                    'daily_challenge_submissions.completed_at',
                    'users.name as user_name',
                    'users.avatar as user_avatar',
                    'grades.name as grade_name',
                    'levels.name as level_name'
                )
                ->join('users', 'users.id', '=', 'daily_challenge_submissions.user_id')
                ->join('daily_challenges_cache', 'daily_challenges_cache.id', '=', 'daily_challenge_submissions.daily_challenge_cache_id')
                ->leftJoin('grades', 'grades.id', '=', 'daily_challenges_cache.grade_id')
                ->leftJoin('levels', 'levels.id', '=', 'daily_challenges_cache.level_id')
                ->orderBy('daily_challenge_submissions.score', 'desc')
                ->orderBy('daily_challenge_submissions.completed_at', 'asc');

            // Filter by level if provided (filters the challenge's level)
            if ($request->has('level_id')) {
                $levelId = $request->get('level_id');
                $query->where('daily_challenges_cache.level_id', $levelId);
            }

            // Filter by grade if provided (filters the challenge's grade)
            if ($request->has('grade_id')) {
                $gradeId = $request->get('grade_id');
                $query->where('daily_challenges_cache.grade_id', $gradeId);
            }

            // Filter by date if provided
            if ($request->has('date')) {
                $date = $request->get('date');
                $query->whereDate('daily_challenges_cache.date', $date);
            } else {
                // Default to today's challenge
                $today = now()->toDateString();
                $query->whereDate('daily_challenges_cache.date', $today);
            }

            $perPage = min($request->get('per_page', 20), 100);
            $leaderboard = $query->paginate($perPage);

            // Transform collection to stable shape
            $leaderboard->getCollection()->transform(function ($entry) {
                return [
                    'id' => $entry->id,
                    'user_id' => $entry->user_id,
                    'name' => $entry->user_name,
                    'avatar' => $entry->user_avatar,
                    'score' => $entry->score,
                    'grade' => $entry->grade_name,
                    'level' => $entry->level_name,
                ];
            });

            return response()->json([
                'data' => $leaderboard->items(),
                'meta' => [
                    'current_page' => $leaderboard->currentPage(),
                    'last_page' => $leaderboard->lastPage(),
                    'per_page' => $leaderboard->perPage(),
                    'total' => $leaderboard->total(),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching daily challenge leaderboard: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching leaderboard data',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ]
            ], 500);
        }
    }

    /**
     * Calculate user's current streak
     */
    private function calculateStreak($user): int
    {
        $submissions = DailyChallengeSubmission::where('user_id', $user->id)
            ->selectRaw('DATE(completed_at) as submission_date')
            ->distinct()
            ->orderByDesc('submission_date')
            ->limit(30)
            ->pluck('submission_date');

        if ($submissions->isEmpty()) {
            return 0;
        }

        $streak = 0;

        for ($i = 0; $i < 30; $i++) {
            $date = now()->subDays($i)->toDateString();

            if ($submissions->contains($date)) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }
}