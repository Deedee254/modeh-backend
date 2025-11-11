<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentBattle;
use App\Models\TournamentBattleAttempt;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentController extends Controller
{
    protected $achievementService;

    public function __construct(AchievementService $achievementService)
    {
        $this->achievementService = $achievementService;
        $this->middleware('auth:sanctum')->except(['index', 'show']);
        $this->middleware('throttle:60,1')->only(['join', 'submitBattle']);
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
        // Eager-load winner, sponsor, and include questions on battles so the frontend can
        // render a current battle inline without an extra request.
        $tournament->load(['subject', 'topic', 'grade', 'level', 'participants', 'battles.questions', 'winner', 'sponsor']);
        $user = auth()->user();

        // Add participation info for current user
        $isParticipant = $tournament->participants()->where('user_id', $user->id)->exists();
        $tournament->is_participant = $isParticipant;

        // Return an explicit JSON shape so callers (frontend) can rely on
        // `tournament` and `winner` keys being present. We include `winner`
        // explicitly even if null to make response shape stable.
        return response()->json([
            'ok' => true,
            'tournament' => $tournament,
            'winner' => $tournament->winner ?? null,
        ]);
    }

    public function join(Request $request, Tournament $tournament)
    {
        $user = $request->user();

        // If the tournament has an entry fee, require either an active subscription
        // or a confirmed one-off purchase for this tournament before allowing join.
        if ($tournament->entry_fee && floatval($tournament->entry_fee) > 0) {
            $activeSub = \App\Models\Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->where(function($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('started_at')
                ->first();

            $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
                ->where('item_type', 'tournament')
                ->where('item_id', $tournament->id)
                ->where('status', 'confirmed')
                ->exists();

            if (!$activeSub && !$hasOneOff) {
                return response()->json([
                    'ok' => false,
                    'code' => 'payment_required',
                    'amount' => (float) $tournament->entry_fee,
                    'item_type' => 'tournament',
                    'item_id' => $tournament->id,
                    'message' => 'Tournament entry fee required'
                ], 402);
            }
        }

        // Verify tournament is joinable
        if ($tournament->status !== 'upcoming' && $tournament->status !== 'active') {
            return response()->json(['message' => 'Tournament is not open for registration'], 400);
        }

        // Check if max participants reached (count only approved participants)
        $approvedCount = DB::table('tournament_participants')
            ->where('tournament_id', $tournament->id)
            ->where('status', 'approved')
            ->count();
        if ($tournament->max_participants && $approvedCount >= $tournament->max_participants) {
            return response()->json(['message' => 'Tournament is full'], 400);
        }

        // Check if user already joined
        if ($tournament->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'Already registered for this tournament'], 400);
        }

        // Add participant. If tournament requires approval, add as pending.
        if ($tournament->requires_approval) {
            $tournament->participants()->attach($user->id, [
                'status' => 'pending',
                'requested_at' => now()
            ]);
            $this->achievementService->checkAchievements($user->id, [
                'type' => 'tournament_joined',
                'tournament_id' => $tournament->id
            ]);

            return response()->json(['message' => 'Registration pending approval', 'status' => 'pending']);
        }

        // Default: immediately approved
        $tournament->participants()->attach($user->id, ['status' => 'approved', 'requested_at' => now(), 'approved_at' => now()]);

        return response()->json(['message' => 'Successfully joined tournament']);
    }

    /**
     * Approve a pending registration (admin only)
     */
    public function approveRegistration(Request $request, Tournament $tournament, $userId)
    {
        $admin = $request->user();
        if (!isset($admin->is_admin) || !$admin->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Ensure participant exists
        if (! $tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        // Approve: update pivot
        try {
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $admin->id
            ]);

            // Notify user
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'approved'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to approve registration'], 500);
        }

        return response()->json(['message' => 'Registration approved']);
    }

    /**
     * Reject a pending registration (admin only)
     */
    public function rejectRegistration(Request $request, Tournament $tournament, $userId)
    {
        $admin = $request->user();
        if (!isset($admin->is_admin) || !$admin->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        try {
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'rejected',
                'approved_at' => now(),
                'approved_by' => $admin->id
            ]);

            // Notify user
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'rejected'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to reject registration'], 500);
        }

        return response()->json(['message' => 'Registration rejected']);
    }

    public function submitBattle(Request $request, TournamentBattle $battle)
    {
        $user = $request->user();
        
        // Validate user is participant
        if ($battle->player1_id !== $user->id && $battle->player2_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        // Validate battle state
        if ($battle->status !== TournamentBattle::STATUS_IN_PROGRESS) {
            return response()->json(['message' => 'Battle is not in progress'], 400);
        }

        if ($battle->has_timed_out) {
            $battle->forfeit($user->id, 'Battle timed out');
            return response()->json(['message' => 'Battle has timed out'], 400);
        }

        // Validate incoming answers array. We compute score server-side to be authoritative.
        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable'
        ]);

        // Load the battle questions so we can grade answers
        $questions = $battle->questions()->get()->keyBy('id');

        // First, build attempts and compute score so we can persist in a single transaction
        $computedScore = 0.0;
        $attempts = [];

        foreach ($data['answers'] as $ans) {
            $qId = (int) ($ans['question_id'] ?? 0);
            $given = $ans['answer'] ?? null;
            if (! $questions->has($qId)) continue;

            $q = $questions->get($qId);
            $marks = $q->marks ?? 1;

            $points = 0;
            if ($q->type === 'mcq') {
                if (! is_null($q->correct) && (string) $q->correct === (string) $given) {
                    $points = $marks;
                } elseif (is_string($given)) {
                    $idx = $q->findOptionIndexByText($given);
                    if (! is_null($idx) && (string) $idx === (string) ($q->correct ?? '')) {
                        $points = $marks;
                    }
                }
            } elseif ($q->type === 'multi') {
                $givenArr = is_array($given) ? $given : (is_string($given) ? json_decode($given, true) : null);
                if (is_array($givenArr) && is_array($q->corrects)) {
                    $corrects = array_map('strval', $q->corrects);
                    $givenStr = array_map('strval', $givenArr);
                    sort($corrects); sort($givenStr);
                    if ($corrects === $givenStr) {
                        $points = $marks;
                    }
                }
            } else {
                if (! empty($q->answers)) {
                    $expected = $q->answers;
                    if (is_string($given) && in_array($given, (array) $expected, true)) {
                        $points = $marks;
                    }
                }
            }

            $answerToStore = is_array($given) ? json_encode($given) : (string) ($given ?? '');
            $pointsValue = round((float) $points, 2);

            $attempts[] = [
                'battle_id' => $battle->id,
                'player_id' => $user->id,
                'question_id' => $qId,
                'answer' => $answerToStore,
                'points' => $pointsValue,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $computedScore += $pointsValue;
        }

        DB::transaction(function() use ($battle, $user, $attempts, $computedScore) {
            // upsert attempts (updateOrCreate per-row to keep logic simple)
            foreach ($attempts as $a) {
                TournamentBattleAttempt::updateOrCreate([
                    'battle_id' => $a['battle_id'],
                    'player_id' => $a['player_id'],
                    'question_id' => $a['question_id']
                ], [
                    'answer' => $a['answer'],
                    'points' => $a['points']
                ]);
            }

            // Update player total score
            $scoreValue = round($computedScore, 2);
            if ($battle->player1_id === $user->id) {
                $battle->player1_score = $scoreValue;
            } else {
                $battle->player2_score = $scoreValue;
            }

            // If both players submitted, determine winner
            if ($battle->player1_score !== null && $battle->player2_score !== null) {
                if ($battle->player1_score > $battle->player2_score) {
                    $battle->winner_id = $battle->player1_id;
                } elseif ($battle->player2_score > $battle->player1_score) {
                    $battle->winner_id = $battle->player2_id;
                }
                $battle->status = TournamentBattle::STATUS_COMPLETED;
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

                // Update the main tournament leaderboard scores
                try {
                    $tournament = $battle->tournament;
                    if ($tournament) {
                        $tournament->participants()->updateExistingPivot($battle->player1_id, ['score' => DB::raw("score + {$battle->player1_score}")]);
                        $tournament->participants()->updateExistingPivot($battle->player2_id, ['score' => DB::raw("score + {$battle->player2_score}")]);
                    }
                } catch (\Exception $e) {
                    // Log error, but don't fail the request
                }
            } else {
                $battle->status = TournamentBattle::STATUS_IN_PROGRESS;
            }

            $battle->save();
        });

        return response()->json([
            'battle' => $battle->fresh(['player1', 'player2', 'winner']),
            'message' => 'Battle submission successful',
            'computed_score' => $computedScore
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
        // Load questions and attempts so we can include per-player responses
        $battle->load(['questions', 'attempts']);

        $initiatorPoints = $battle->player1_score ?? 0;
        $opponentPoints = $battle->player2_score ?? 0;

        // Group attempts by question_id for quick lookup
        $attemptsByQuestion = $battle->attempts->groupBy('question_id');

        $questions = [];
        foreach ($battle->questions as $q) {
            $initiator = null;
            $opponent = null;

            $group = $attemptsByQuestion->get($q->id) ?? collect();
            foreach ($group as $att) {
                if ($att->player_id === $battle->player1_id) {
                    $initiator = $att->answer;
                } elseif ($att->player_id === $battle->player2_id) {
                    $opponent = $att->answer;
                }
            }

            $questions[] = [
                'question_id' => $q->id,
                'body' => $q->body,
                'initiator' => $initiator,
                'opponent' => $opponent,
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

    /**
     * Mark a tournament battle (reveal results) for the requesting user.
     * Requires active subscription or one-off purchase for the tournament (entry fee).
     */
    public function forfeitBattle(Request $request, TournamentBattle $battle)
    {
        $user = $request->user();
        
        // Validate user is participant
        if ($battle->player1_id !== $user->id && $battle->player2_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        // Validate battle can be forfeited
        if ($battle->status !== TournamentBattle::STATUS_IN_PROGRESS) {
            return response()->json(['message' => 'Battle cannot be forfeited'], 400);
        }

        // Execute forfeit
        try {
            DB::beginTransaction();
            
            $battle->forfeit(
                $user->id, 
                $request->input('reason', 'Player forfeited the battle')
            );
            
            DB::commit();
            
            return response()->json([
                'message' => 'Battle forfeited successfully',
                'battle' => $battle->fresh(['player1', 'player2', 'winner'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to forfeit battle'], 500);
        }
    }

    public function mark(Request $request, Tournament $tournament, TournamentBattle $battle)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // Check subscription
        $activeSub = \App\Models\Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();

        // Check one-off purchase for the tournament (pay once for the whole tournament)
        $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'tournament')
            ->where('item_id', $tournament->id)
            ->where('status', 'confirmed')
            ->exists();

        if (!$activeSub && !$hasOneOff) {
            return response()->json(['ok' => false, 'message' => 'Subscription or tournament purchase required'], 403);
        }

        // Enforce package limits similar to battles if package defines limits
        if ($activeSub && $activeSub->package && is_array($activeSub->package->features)) {
            $features = $activeSub->package->features;
            $limit = $features['limits']['battle_results'] ?? $features['limits']['results'] ?? null;
            if ($limit !== null) {
                $today = now()->startOfDay();
                $used = TournamentBattle::where(function($q) use ($user) {
                        $q->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
                    })->whereNotNull('completed_at')
                    ->where('completed_at', '>=', $today)
                    ->count();
                if ($used >= intval($limit)) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'limit_reached',
                        'limit' => [
                            'type' => 'battle_results',
                            'value' => intval($limit)
                        ],
                        'message' => 'Daily result reveal limit reached for your plan'
                    ], 403);
                }
            }
        }

        // Only allow participants
        if (!in_array($user->id, [$battle->player1_id, $battle->player2_id])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Delegate to existing result() which builds the response shape for a tournament battle
        return $this->result($request, $tournament, $battle);
    }

    /**
     * Return the current user's registration status for a tournament
     */
    public function registrationStatus(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['isRegistered' => false]);
        }

        $participant = $tournament->participants()->where('user_id', $user->id)->first();
        if (! $participant) {
            return response()->json(['isRegistered' => false]);
        }

        return response()->json([
            'isRegistered' => true,
            'status' => $participant->pivot->status ?? 'approved'
        ]);
    }
}