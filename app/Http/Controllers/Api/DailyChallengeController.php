<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\UserDailyChallenge;
use App\Services\AchievementService;
use Illuminate\Http\Request;

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

        // Check achievements
        $this->achievementService->checkAchievements($user->id, [
            'type' => 'daily_challenge_completed',
            'score' => $data['score'],
            'challenge_id' => $challenge->id,
        ]);

        // Return updated challenge with completion status
        $challenge->load('grade', 'subject');
        return response()->json([
            'challenge' => $challenge,
            'completion' => $udc,
            'score' => $data['score']
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
                    'users.name',
                    'users.avatar'
                )
                ->join('users', 'users.id', '=', 'user_daily_challenges.user_id')
                ->orderBy('user_daily_challenges.score', 'desc')
                ->orderBy('user_daily_challenges.completed_at', 'asc');

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