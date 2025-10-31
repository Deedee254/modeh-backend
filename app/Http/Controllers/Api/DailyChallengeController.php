<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\UserDailyChallenge;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DailyChallengeController extends Controller
{
    protected $achievementService;

    public function __construct(AchievementService $achievementService)
    {
        $this->achievementService = $achievementService;
        // Only require auth for non-public endpoints
        $this->middleware('auth:sanctum')->except(['leaderboard']);
    }

    /**
     * Get today's daily challenge
     */
    public function today(Request $request)
    {
        $user = $request->user();
        $today = now()->toDateString();

        // Migration stores the date in `challenge_date` (see migration), so query that column.
        $challenge = DailyChallenge::whereDate('challenge_date', $today)
            ->where('is_active', true)
            ->with('grade', 'subject')
            ->first();

        if (!$challenge) {
            return response()->json(['challenge' => null, 'completion' => null]);
        }

        // Check if user has completed it
        $completion = UserDailyChallenge::where('user_id', $user->id)
            ->where('daily_challenge_id', $challenge->id)
            ->first();

        return response()->json([
            'challenge' => $challenge,
            'completion' => $completion
        ]);
    }

    /**
     * Get user's daily challenge history
     */
    public function history(Request $request)
    {
        $user = $request->user();

        $completions = UserDailyChallenge::where('user_id', $user->id)
            ->with('dailyChallenge')
            ->orderBy('completed_at', 'desc')
            ->get();

        return response()->json($completions);
    }

    /**
     * Submit a daily challenge attempt
     */
    public function submit(Request $request, DailyChallenge $challenge)
    {
        $user = $request->user();
        $data = $request->validate([
            'answers' => 'required|array',
            'score' => 'required|numeric|min:0|max:100'
        ]);

        // Create completion record
        $udc = UserDailyChallenge::create([
            'user_id' => $user->id,
            'daily_challenge_id' => $challenge->id,
            'completed_at' => now(),
            'score' => $data['score']
        ]);

        // Check achievements and collect awarded achievements
        $awarded = $this->achievementService->checkAchievements($user->id, [
            'type' => 'daily_challenge_completed',
            'score' => $data['score'],
            'challenge_id' => $challenge->id,
        ]);

        // Refresh user to include newly awarded achievements/points
        $user = $user->fresh()->load('achievements');

        // Return updated challenge with completion status
        $challenge->load('grade', 'subject');
        return response()->json([
            'challenge' => $challenge,
            'completion' => $udc,
            'score' => $data['score'],
            'awarded_achievements' => $awarded,
            'user' => $user,
        ]);
    }

    /**
     * Get daily challenge leaderboard
     */
    public function leaderboard(Request $request)
    {
        try {
            $query = UserDailyChallenge::query()
                ->select(
                    'user_daily_challenges.id',
                    'user_daily_challenges.user_id',
                    'user_daily_challenges.score',
                    'user_daily_challenges.completed_at',
                    'users.name as user_name',
                    'users.avatar as user_avatar',
                    'grades.name as grade_name',
                    'levels.name as level_name'
                )
                ->join('users', 'users.id', '=', 'user_daily_challenges.user_id')
                ->leftJoin('quizees', 'quizees.user_id', '=', 'users.id')
                ->leftJoin('grades', 'grades.id', '=', 'quizees.grade_id');

            // Join `levels` safely: prefer quizees.level_id if the column exists, otherwise fall back to grades.level_id.
            if (Schema::hasColumn('quizees', 'level_id')) {
                $query->leftJoin('levels', 'levels.id', '=', 'quizees.level_id');
            } else {
                // fallback: levels associated with grades
                $query->leftJoin('levels', 'levels.id', '=', 'grades.level_id');
            }
            $query->orderBy('user_daily_challenges.score', 'desc')
                ->orderBy('user_daily_challenges.completed_at', 'asc');

            // Filter by level if provided (filters users by their profile level)
            if ($request->has('level_id')) {
                $levelId = $request->get('level_id');
                $query->whereHas('user.quizeeProfile', function ($q) use ($levelId) {
                    $q->where('level_id', $levelId);
                });
            }

            // Filter by grade/course if provided (filters the challenge itself)
            if ($request->has('grade_id')) {
                $gradeId = $request->get('grade_id');
                $query->whereHas('dailyChallenge', function ($q) use ($gradeId) {
                    $q->where('grade_id', $gradeId);
                });
            }

            // Filter by date if provided
            if ($request->has('date')) {
                $date = $request->get('date');
                $query->whereHas('dailyChallenge', function($q) use ($date) {
                    $q->whereDate('challenge_date', $date);
                });
            } else {
                // Default to today's challenge
                $today = now()->toDateString();
                $query->whereHas('dailyChallenge', function($q) use ($today) {
                    $q->whereDate('challenge_date', $today);
                });
            }

            $perPage = min($request->get('per_page', 20), 100); // Limit max items per page
            $leaderboard = $query->paginate($perPage);

            // Transform the collection to a stable shape expected by the frontend
            $leaderboard->getCollection()->transform(function ($entry) {
                return [
                    'id' => $entry->id,
                    'user_id' => $entry->user_id,
                    'name' => $entry->user_name, // Use alias
                    'avatar' => $entry->user_avatar, // Use alias
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
}