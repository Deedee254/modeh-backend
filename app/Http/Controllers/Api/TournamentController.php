<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentBattle;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentController extends Controller
{
    protected $achievementService;

    public function __construct(AchievementService $achievementService)
    {
        $this->achievementService = $achievementService;
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        $query = Tournament::with(['subject', 'topic', 'grade']);

        // Filter by status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Filter by subject
        if ($subjectId = $request->get('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        // Filter by grade
        if ($gradeId = $request->get('grade_id')) {
            $query->where('grade_id', $gradeId);
        }

        // Include participants_count to make frontend rendering simpler
        $tournaments = $query->withCount('participants')->latest()->paginate(20);
        return response()->json($tournaments);
    }

    public function show(Tournament $tournament)
    {
        $tournament->load(['subject', 'topic', 'grade', 'participants', 'battles']);
        $user = auth()->user();

        // Add participation info for current user
        $isParticipant = $tournament->participants()->where('user_id', $user->id)->exists();
        $tournament->is_participant = $isParticipant;

        return response()->json($tournament);
    }

    public function join(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        // Verify tournament is joinable
        if ($tournament->status !== 'upcoming' && $tournament->status !== 'active') {
            return response()->json(['message' => 'Tournament is not open for registration'], 400);
        }

        // Check if max participants reached
        if ($tournament->max_participants && $tournament->participants()->count() >= $tournament->max_participants) {
            return response()->json(['message' => 'Tournament is full'], 400);
        }

        // Check if user already joined
        if ($tournament->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Already registered for this tournament'], 400);
        }

        // Add participant
        $tournament->participants()->attach($user->id);

        // Check achievements
        $this->achievementService->checkAchievements($user->id, [
            'type' => 'tournament_joined',
            'tournament_id' => $tournament->id
        ]);

        return response()->json(['message' => 'Successfully joined tournament']);
    }

    public function submitBattle(Request $request, TournamentBattle $battle)
    {
        $user = $request->user();
        
        // Validate user is participant
        if ($battle->player1_id !== $user->id && $battle->player2_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $data = $request->validate([
            'answers' => 'required|array',
            'score' => 'required|numeric|min:0'
        ]);

        DB::transaction(function() use ($battle, $user, $data) {
            // Update player score
            if ($battle->player1_id === $user->id) {
                $battle->player1_score = $data['score'];
            } else {
                $battle->player2_score = $data['score'];
            }

            // If both players submitted, determine winner
            if ($battle->player1_score !== null && $battle->player2_score !== null) {
                if ($battle->player1_score > $battle->player2_score) {
                    $battle->winner_id = $battle->player1_id;
                } elseif ($battle->player2_score > $battle->player1_score) {
                    $battle->winner_id = $battle->player2_id;
                }
                $battle->status = 'completed';
                $battle->completed_at = now();

                // Check achievements for both players
                foreach ([$battle->player1_id, $battle->player2_id] as $playerId) {
                    $playerScore = $playerId === $battle->player1_id ? $battle->player1_score : $battle->player2_score;
                    $isWinner = $battle->winner_id === $playerId;

                    $this->achievementService->checkAchievements($playerId, [
                        'type' => $isWinner ? 'tournament_battle_won' : 'tournament_battle_completed',
                        'tournament_id' => $battle->tournament_id,
                        'battle_id' => $battle->id,
                        'score' => $playerScore
                    ]);
                }
            } else {
                $battle->status = 'in_progress';
            }

            $battle->save();
        });

        return response()->json([
            'battle' => $battle->fresh(['player1', 'player2', 'winner']),
            'message' => 'Battle submission successful'
        ]);
    }

    public function leaderboard(Tournament $tournament)
    {
        // Load participants and map pivot values to keys the frontend expects
        $participants = $tournament->participants()->withPivot('score', 'rank', 'completed_at')->get();

        $leaderboard = $participants->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'avatar' => $p->avatar ?? null,
                // normalize pivot score to `points` for frontend convenience
                'points' => $p->pivot->score ?? null,
                'rank' => $p->pivot->rank ?? null,
                'completed_at' => $p->pivot->completed_at ?? null,
            ];
        })->sortByDesc('points')->values();

        return response()->json([
            'tournament' => $tournament->only(['id', 'name', 'status']),
            'leaderboard' => $leaderboard
        ]);
    }

    /**
     * Return result for a tournament battle in a shape compatible with BattleController::result
     */
    public function result(Request $request, Tournament $tournament, TournamentBattle $battle)
    {
        $battle->load('questions');

        $initiatorPoints = $battle->player1_score ?? 0;
        $opponentPoints = $battle->player2_score ?? 0;

        $questions = [];
        foreach ($battle->questions as $q) {
            $questions[] = [
                'question_id' => $q->id,
                'body' => $q->body,
                'initiator' => null,
                'opponent' => null,
                'correct' => is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [],
            ];
        }

        $result = [
            'score' => max($initiatorPoints, $opponentPoints),
            'initiator_correct' => $initiatorPoints,
            'opponent_correct' => $opponentPoints,
            'total' => count($questions),
            'questions' => $questions,
            'battle' => $battle,
        ];

        return response()->json(['ok' => true, 'result' => $result]);
    }
}