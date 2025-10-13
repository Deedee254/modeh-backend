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
        $this->middleware('auth:sanctum');
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
        $completion = UserDailyChallenge::where('quizee_id', $user->id)
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

        $completions = UserDailyChallenge::where('quizee_id', $user->id)
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
            'quizee_id' => $user->id,
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
}