<?php

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\Tournament\TournamentBracketDto;
use App\DataTransferObjects\Tournament\TournamentMatchDto;
use App\DataTransferObjects\Tournament\TournamentRoundDto;
use App\DataTransferObjects\Tournament\WinnerDto;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentBattle;
use App\Models\TournamentBattleAttempt;
use App\Models\TournamentQualificationAttempt;
use App\Services\AchievementService;
use App\Services\QuestionMarkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TournamentController extends Controller
{
    protected $achievementService;
    protected $markingService;

    public function __construct(AchievementService $achievementService, QuestionMarkingService $markingService)
    {
        $this->achievementService = $achievementService;
        $this->markingService = $markingService;
        $this->middleware('auth:sanctum')->except(['index', 'show', 'tree']);
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
    // Eager-load commonly used relations. Include the tournament-level questions
    // so frontend callers (qualifier) receive the configured question set without
    // needing an extra request.
    $tournament->load(['subject', 'topic', 'grade', 'level', 'participants', 'questions', 'battles.questions', 'winner', 'sponsor']);
        $user = Auth::user();

        // Add participation info for current user (guard when no authenticated user)
        if ($user) {
            $isParticipant = $tournament->participants()->where('user_id', $user->id)->exists();
        } else {
            $isParticipant = false;
        }
        $tournament->is_participant = $isParticipant;

        // Compute eligibility details for the current user so frontend can
        // surface whether the user is eligible to join and why if not.
        $eligibility = [
            'can_join' => false,
            'reason' => null,
        ];

        if ($user) {
            try {
                // Use the Gate inspector so we don't throw an exception here but
                // can read the allow/deny state and optional message from the
                // policy (e.g. "You are not in the correct grade...").
                $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);
                $inspect = $gate->forUser($user)->inspect('join', $tournament);

                $eligibility['can_join'] = $inspect->allowed();
                $eligibility['reason'] = $inspect->message() ?? null;
            } catch (\Throwable $e) {
                // If inspect isn't available for some reason, fall back to a
                // conservative response (deny) and capture the exception message
                // for debugging/UI display.
                $eligibility['can_join'] = false;
                $eligibility['reason'] = $e->getMessage();
            }
        } else {
            $eligibility['can_join'] = false;
            $eligibility['reason'] = 'authentication_required';
        }

        // Generate a per-response shuffle seed and shuffle questions/options
        // so clients receive a randomized ordering to discourage sharing.
        try {
            $shuffleSeed = bin2hex(random_bytes(6));
        } catch (\Exception $_) {
            $shuffleSeed = (string) time();
        }

        // Shuffle question order deterministically using seed
        if ($tournament->relationLoaded('questions') && $tournament->questions->isNotEmpty()) {
            $questions = $tournament->questions->map(function ($q) use ($shuffleSeed) {
                // Shuffle options for each question using a seed that incorporates question id
                $opts = [];
                if (is_array($q->options)) {
                    $opts = $q->options;
                } elseif (is_string($q->options)) {
                    $decoded = json_decode((string) $q->options, true);
                    if (is_array($decoded)) $opts = $decoded;
                }
                if (!empty($opts)) {
                    // Capture correct values before shuffling to remap indices
                    $correctValues = [];
                    $isMcq = $q->type === 'mcq';
                    $isMulti = $q->type === 'multi';
                    
                    if ($isMcq && !is_null($q->correct) && isset($opts[$q->correct])) {
                        $correctValues[] = $opts[$q->correct];
                    } elseif ($isMulti && !empty($q->corrects)) {
                        $indices = is_array($q->corrects) ? $q->corrects : json_decode($q->corrects, true);
                        if (is_array($indices)) {
                            foreach ($indices as $idx) {
                                if (isset($opts[$idx])) {
                                    $correctValues[] = $opts[$idx];
                                }
                            }
                        }
                    }

                    $shuffled = $this->seededShuffle($opts, $shuffleSeed . '::' . $q->id);
                    $q->options = $shuffled;

                    // Remap correct indices
                    if ($isMcq && !empty($correctValues)) {
                        $newIdx = array_search($correctValues[0], $shuffled);
                        if ($newIdx !== false) {
                            $q->correct = $newIdx;
                        }
                    } elseif ($isMulti && !empty($correctValues)) {
                        $newIndices = [];
                        foreach ($correctValues as $val) {
                            $newIdx = array_search($val, $shuffled);
                            if ($newIdx !== false) {
                                $newIndices[] = $newIdx;
                            }
                        }
                        sort($newIndices);
                        $q->corrects = $newIndices;
                    }
                }
                return $q;
            })->toArray();

            // Shuffle questions order
            $shuffledQuestions = $this->seededShuffle($questions, $shuffleSeed);
            $tournament->setRelation('questions', collect($shuffledQuestions));
        }

        // Return an explicit JSON shape so callers (frontend) can rely on
        // `tournament`, `winner` and `eligibility` keys being present.
        return response()->json([
            'ok' => true,
            'tournament' => $tournament,
            'winner' => $tournament->winner ?? null,
            'eligibility' => $eligibility,
            'shuffle_seed' => $shuffleSeed,
        ]);
    }

    /**
     * Show a single tournament battle with shuffled questions/options and a shuffle seed.
     */
    public function showBattle(Request $request, Tournament $tournament, TournamentBattle $battle)
    {
        // Ensure battle belongs to tournament
        if ($battle->tournament_id !== $tournament->id) {
            return response()->json(['message' => 'Battle not found for this tournament'], 404);
        }

        $battle->load(['questions']);

        // Generate a per-response shuffle seed
        try {
            $shuffleSeed = bin2hex(random_bytes(6));
        } catch (\Exception $_) {
            $shuffleSeed = (string) time();
        }

        // Shuffle options per-question and question order deterministically
        $questions = $battle->questions->map(function ($q) use ($shuffleSeed) {
            $opts = [];
            if (is_array($q->options)) {
                $opts = $q->options;
            } elseif (is_string($q->options)) {
                $decoded = json_decode((string) $q->options, true);
                if (is_array($decoded)) $opts = $decoded;
            }
            if (!empty($opts)) {
                // Capture correct values before shuffling to remap indices
                $correctValues = [];
                $isMcq = $q->type === 'mcq';
                $isMulti = $q->type === 'multi';
                
                if ($isMcq && !is_null($q->correct) && isset($opts[$q->correct])) {
                    $correctValues[] = $opts[$q->correct];
                } elseif ($isMulti && !empty($q->corrects)) {
                    $indices = is_array($q->corrects) ? $q->corrects : json_decode($q->corrects, true);
                    if (is_array($indices)) {
                        foreach ($indices as $idx) {
                            if (isset($opts[$idx])) {
                                $correctValues[] = $opts[$idx];
                            }
                        }
                    }
                }

                $q->options = $this->seededShuffle($opts, $shuffleSeed . '::' . $q->id);
                $shuffled = $q->options;

                // Remap correct indices
                if ($isMcq && !empty($correctValues)) {
                    $newIdx = array_search($correctValues[0], $shuffled);
                    if ($newIdx !== false) {
                        $q->correct = $newIdx;
                    }
                } elseif ($isMulti && !empty($correctValues)) {
                    $newIndices = [];
                    foreach ($correctValues as $val) {
                        $newIdx = array_search($val, $shuffled);
                        if ($newIdx !== false) {
                            $newIndices[] = $newIdx;
                        }
                    }
                    sort($newIndices);
                    $q->corrects = $newIndices;
                }
            }
            return $q;
        })->toArray();

        $shuffledQuestions = $this->seededShuffle($questions, $shuffleSeed);
        $battle->setRelation('questions', collect($shuffledQuestions));

        return response()->json([
            'ok' => true,
            'battle' => $battle,
            'shuffle_seed' => $shuffleSeed,
        ]);
    }

    /**
     * Get all battles for a tournament (paginated)
     */
    public function battles(Tournament $tournament, Request $request)
    {
        // Filter by round if specified
        $query = $tournament->battles();
        
        if ($request->has('round')) {
            $query->where('round', $request->get('round'));
        }

        // Filter by status if specified
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Load related data
        $battles = $query
            ->with(['player1', 'player2', 'winner', 'questions'])
            ->orderBy('round', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'ok' => true,
            'data' => $battles->items(),
            'pagination' => [
                'total' => $battles->total(),
                'count' => $battles->count(),
                'per_page' => $battles->perPage(),
                'current_page' => $battles->currentPage(),
                'last_page' => $battles->lastPage(),
            ]
        ]);
    }

    public function join(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        $this->authorize('join', $tournament);
        // Determine payment / registration status but allow registration even when unpaid.
        // New behavior: create a participant record and set status to:
        // - 'paid' when tournament is free or user has a confirmed payment/subscription
        // - 'pending_payment' when user hasn't paid yet
        // This removes the manual approval step and uses payment status as the gating property.

        // Verify tournament is joinable
        if ($tournament->status !== 'upcoming' && $tournament->status !== 'active') {
            return response()->json(['message' => 'Tournament is not open for registration'], 400);
        }

        // Accept optional payment reference from frontend to immediately verify purchase
        $paymentRef = $request->input('payment_reference');

        return DB::transaction(function() use ($tournament, $user, $paymentRef) {
            // Lock the tournament row to ensure no concurrent modifications to participant count
            $lockedTournament = Tournament::lockForUpdate()->find($tournament->id);

            // Payment checks
            $fee = $lockedTournament->entry_fee && floatval($lockedTournament->entry_fee) > 0;
            $activeSub = \App\Models\Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->where(function($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('started_at')
                ->first();

            // If a payment_reference was provided, try to resolve it to a OneOffPurchase and confirm ownership
            $hasOneOff = false;
            if ($paymentRef) {
                $purchase = \App\Models\OneOffPurchase::where(function($q) use ($paymentRef) {
                    $q->where('id', $paymentRef)->orWhere('gateway_meta->tx', $paymentRef);
                })->first();
                if ($purchase && $purchase->user_id === $user->id && $purchase->item_type === 'tournament' && $purchase->item_id == $lockedTournament->id && $purchase->status === 'confirmed') {
                    $hasOneOff = true;
                }
            }

            if (! $hasOneOff) {
                $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
                    ->where('item_type', 'tournament')
                    ->where('item_id', $lockedTournament->id)
                    ->where('status', 'confirmed')
                    ->exists();
            }

            // If tournament is marked open_to_subscribers, an active subscription counts as paid access
            $isPaid = !$fee || $hasOneOff || ($activeSub && (bool) ($lockedTournament->open_to_subscribers ?? false));

            // Check if user already joined (any status)
            $existingParticipant = DB::table('tournament_participants')
                ->where('tournament_id', $lockedTournament->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingParticipant) {
                if ($existingParticipant->status === 'paid') {
                    return response()->json(['message' => 'Already registered for this tournament'], 400);
                }
                if ($existingParticipant->status === 'pending_payment') {
                    return response()->json(['message' => 'Registration pending payment', 'status' => 'pending_payment'], 202);
                }
                if ($existingParticipant->status === 'rejected') {
                    return response()->json(['message' => 'Your registration was rejected'], 403);
                }
            }

            // Check: prevent user from joining multiple battles in the same tournament round
            $currentRound = $lockedTournament->battles()->max('round') ?? 0;
            if ($currentRound > 0) {
                $alreadyInRound = DB::table('tournament_battles')
                    ->where('tournament_id', $lockedTournament->id)
                    ->where('round', $currentRound)
                    ->where(function($q) use ($user) {
                        $q->where('player1_id', $user->id)
                          ->orWhere('player2_id', $user->id);
                    })
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if ($alreadyInRound) {
                    return response()->json(['message' => 'You are already in a battle for this round'], 400);
                }
            }

            // Determine initial status based on payment
            $status = $isPaid ? 'paid' : 'pending_payment';

            // Insert participant record (pending or paid). Set approved_at for paid to preserve existing timestamps.
            DB::table('tournament_participants')->insert([
                'tournament_id' => $lockedTournament->id,
                'user_id' => $user->id,
                'status' => $status,
                'requested_at' => now(),
                'approved_at' => $status === 'paid' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Check achievements
            $this->achievementService->checkAchievements($user->id, [
                'type' => 'tournament_joined',
                'tournament_id' => $lockedTournament->id
            ]);

            if ($status === 'pending_payment') {
                return response()->json([
                    'message' => 'Registration recorded; payment pending',
                    'status' => 'pending_payment',
                    'code' => 'pending_payment',
                    'amount' => (float) $lockedTournament->entry_fee,
                    'item_type' => 'tournament',
                    'item_id' => $lockedTournament->id
                ], 202);
            }

            return response()->json(['message' => 'Successfully joined tournament', 'status' => 'paid']);
        });
    }

    /**
     * Approve a pending registration (admin only)
     */
    public function approveRegistration(Request $request, Tournament $tournament, $userId)
    {
        $this->authorize('approveRegistration', Tournament::class);

        // Ensure participant exists
        if (! $tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        // Approve: update pivot
        try {
            // In the new payment-driven model, admin "approve" acts as a manual mark-as-paid (e.g., cash payment received)
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'paid',
                'approved_at' => now(),
                'approved_by' => $request->user()->id
            ]);

            // Notify user that their registration has been marked as paid
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'paid'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to mark registration as paid'], 500);
        }

        return response()->json(['message' => 'Registration marked as paid']);
    }

    /**
     * Reject a pending registration (admin only)
     */
    public function rejectRegistration(Request $request, Tournament $tournament, $userId)
    {
        $this->authorize('rejectRegistration', Tournament::class);

        if (! $tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        try {
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'rejected',
                'approved_at' => now(),
                'approved_by' => $request->user()->id
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

        // RE-VALIDATE PAYMENT: Ensure user still has valid payment for tournament entry fee
        $tournament = $battle->tournament;
        if ($tournament && $tournament->entry_fee && floatval($tournament->entry_fee) > 0) {
            $activeSub = \App\Models\Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->where(function($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->first();

            $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
                ->where('item_type', 'tournament')
                ->where('item_id', $tournament->id)
                ->where('status', 'confirmed')
                ->where('expires_at', '>', now())
                ->exists();

            if (!$activeSub && !$hasOneOff) {
                return response()->json([
                    'code' => 'payment_expired',
                    'message' => 'Payment verification failed - subscription expired or invalid'
                ], 402);
            }
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
            
            // Validate question ID exists in battle
            if (! $questions->has($qId)) {
                throw new \InvalidArgumentException("Question {$qId} not found in this battle");
            }

            $q = $questions->get($qId);
            $marks = $q->marks ?? 1;

            // Normalize correct answers for the marking service
            $correctAnswers = [];
            if ($q->type === 'mcq') {
                if (! is_null($q->correct)) {
                    $correctAnswers = [(string) $q->correct];
                } elseif (! empty($q->answers)) {
                    $correctAnswers = is_array($q->answers) ? $q->answers : [$q->answers];
                }
            } elseif ($q->type === 'multi') {
                if (is_array($q->corrects)) {
                    $correctAnswers = $q->corrects;
                } elseif (is_string($q->corrects)) {
                    $decoded = json_decode($q->corrects, true);
                    $correctAnswers = is_array($decoded) ? $decoded : [];
                }
            } else {
                $correctAnswers = $q->answers ?? [];
            }

            $isCorrect = $this->markingService->isAnswerCorrect($given, $correctAnswers, $q);
            $points = $isCorrect ? $marks : 0;

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

                // Update the main tournament leaderboard scores using safe increment
                try {
                    $tournament = $battle->tournament;
                    if ($tournament) {
                        // Use safe increment instead of raw SQL
                        DB::table('tournament_participants')
                            ->where('tournament_id', $tournament->id)
                            ->where('user_id', $battle->player1_id)
                            ->increment('score', (float) $battle->player1_score);
                        
                        DB::table('tournament_participants')
                            ->where('tournament_id', $tournament->id)
                            ->where('user_id', $battle->player2_id)
                            ->increment('score', (float) $battle->player2_score);
                    }
                } catch (\Exception $e) {
                    // Log error and re-throw to fail the transaction
                    Log::error('Failed to update tournament leaderboard', [
                        'tournament_id' => $tournament->id ?? null,
                        'battle_id' => $battle->id,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            } else {
                $battle->status = TournamentBattle::STATUS_IN_PROGRESS;
            }

            $battle->save();
        });

        // Auto-check: if round complete, close and advance; if round end date passed, attempt automatic closure too.
        try {
            $tournament = $battle->tournament;
            if ($tournament) {
                if ($tournament->isRoundComplete($battle->round)) {
                    // Round finished by play; force close and advance
                    $tournament->closeRoundAndAdvance($battle->round, true);
                } else {
                    // If round scheduled start + round_delay_days has passed, attempt to close automatically
                    $roundStart = $tournament->battles()->where('round', $battle->round)->pluck('scheduled_at')->filter()->max();
                    if ($roundStart && $tournament->round_delay_days) {
                        $roundEnd = \Carbon\Carbon::parse($roundStart)->addDays(max(1, (int)$tournament->round_delay_days));
                        if (now()->gte($roundEnd)) {
                            $tournament->closeRoundAndAdvance($battle->round, false);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::info('Auto-round-check failed (non-critical): ' . $e->getMessage());
        }

        return response()->json([
            'battle' => $battle->fresh(['player1', 'player2', 'winner']),
            'message' => 'Battle submission successful',
            'computed_score' => $computedScore
        ]);
    }

    public function leaderboard(Tournament $tournament)
    {
        // Detect if tournament is in qualifier phase: upcoming status and no battles created yet
        $hasAnyBattle = $tournament->battles()->exists();
        $isQualifierPhase = $tournament->status === 'upcoming' && !$hasAnyBattle;

        if ($isQualifierPhase) {
            // Return qualifier leaderboard from qualification attempts (sorted by score desc, then duration asc for tie-breaking)
            // Eager-load user to avoid N+1 queries. A qualification attempt may
            // reference a user that has been deleted, so guard accesses with
            // fallbacks to attempt columns where appropriate.
            $attempts = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
                ->with('user:id,name,avatar,avatar_url')
                ->orderByDesc('score')
                ->orderBy('duration_seconds')
                ->get();

            $leaderboard = $attempts->map(function ($attempt) {
                $user = $attempt->user;
                $userId = $user->id ?? $attempt->user_id;
                $name = $user->name ?? null;
                $avatarUrl = $user->avatar_url ?? null;
                $avatar = $user->avatar ?? null;

                return [
                    'id' => $userId,
                    'name' => $name,
                    'avatar_url' => $avatarUrl,
                    'avatar' => $avatar,
                    'points' => $attempt->score,
                    'duration_seconds' => $attempt->duration_seconds,
                    'completed_at' => $attempt->created_at,
                ];
            })->values();
        } else {
            // Return bracket leaderboard from tournament battles
            $participants = $tournament->participants()
                ->where('role', 'quizee')
                ->withPivot('score', 'rank', 'completed_at')
                ->get();

            $leaderboard = $participants->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'avatar_url' => $p->avatar_url ?? null,
                    'avatar' => $p->avatar ?? null,
                    'points' => $p->pivot->score ?? null,
                    'rank' => $p->pivot->rank ?? null,
                    'completed_at' => $p->pivot->completed_at ?? null,
                ];
            })->sortByDesc('points')->values();
        }

        return response()->json([
            'tournament' => $tournament->only(['id', 'name', 'status']),
            'leaderboard' => $leaderboard,
            'is_qualifier_phase' => $isQualifierPhase
        ]);
    }

    /**
     * Get qualifier-specific leaderboard (for any tournament)
     * Always returns qualification attempts regardless of tournament status
     */
    public function qualifierLeaderboard(Tournament $tournament, Request $request)
    {
        $per_page = $request->get('per_page', 50);
        
        $attempts = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->with('user:id,name,email,avatar,avatar_url')
            ->orderByDesc('score')
            ->orderBy('duration_seconds')
            ->paginate($per_page);

        return response()->json([
            'data' => $attempts->map(function ($attempt) {
                $user = $attempt->user;
                return [
                    'id' => $attempt->id,
                    'user_id' => $attempt->user_id,
                    'user_name' => $user->name ?? null,
                    'user_email' => $user->email ?? null,
                    'avatar' => $user->avatar ?? null,
                    'user_avatar' => $user->avatar ?? null,
                    'score' => $attempt->score,
                    'duration_seconds' => $attempt->duration_seconds,
                    'status' => $attempt->status ?? 'completed',
                    'completed_at' => $attempt->created_at,
                ];
            }),
            'pagination' => [
                'total' => $attempts->total(),
                'count' => $attempts->count(),
                'per_page' => $attempts->perPage(),
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
            ]
        ]);
    }

    /**
     * Get tournament bracket structure organized by rounds
     * Returns complete bracket with player info, scores, and match results
     */
    /**
     * Return the tournament bracket tree organized by rounds.
     *
     * This endpoint returns a JSON structure with rounds and matches. For static
     * analysis and editor completion, we provide an explicit docblock describing
     * the expected parameter and return types. The payload may include optional
     * detailed `questions` and `attempts` arrays for authorized requesters.
     *
     * @param Tournament $tournament
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function tree(Tournament $tournament, Request $request)
    {
        // Eager-load attempts to allow DTOs to use the relationship without triggering extra queries
        $battles = $tournament->battles()
            ->with([
                'player1:id,name,avatar_url,avatar',
                'player2:id,name,avatar_url,avatar',
                'winner:id,name,avatar_url,avatar',
                'questions:id,marks',
                'attempts:id,battle_id,player_id,question_id,answer,points',
                'attempts.player:id,name,avatar_url,avatar',
            ])
            ->get();

        $roundGroups = $battles->groupBy('round');
        $totalRounds = $roundGroups->count() ?: 0;
        $currentRound = $roundGroups->keys()->max() ?? 0;
        $user = $request->user();
        $userId = $user?->id;
        $isAdmin = $user && method_exists($user, 'can') && $user->can('viewFilament');

        $bracketRounds = [];
        foreach ($roundGroups as $roundNum => $roundBattles) {
            $isRoundComplete = $tournament->isRoundComplete((int) $roundNum);

            $matches = $roundBattles->map(function ($battle) use ($isRoundComplete, $isAdmin, $userId) {
                $canViewDetails = false;
                if ($userId) {
                    if ($userId === $battle->player1_id || $userId === $battle->player2_id) $canViewDetails = true;
                    if ($isAdmin) $canViewDetails = true;
                }
                return TournamentMatchDto::fromModel($battle, $canViewDetails, $isRoundComplete, $isAdmin, $userId);
            })->all();

            $roundStatus = $tournament->getRoundStatus($roundNum);
            $roundEndDate = $roundBattles->pluck('scheduled_at')->filter()->max();

            $bracketRounds[] = new TournamentRoundDto(
                round: (int) $roundNum,
                matches: $matches,
                match_count: count($matches),
                status: $roundStatus,
                round_end_date: $roundEndDate,
                is_complete: $roundStatus['completed'] === $roundStatus['total'] && $roundStatus['total'] > 0,
                is_current: $roundNum === $currentRound
            );
        }

        ksort($bracketRounds);

        $winnerDto = $tournament->winner ? WinnerDto::fromModel($tournament->winner) : null;

        $bracketDto = new TournamentBracketDto(
            ok: true,
            tournament: (object) $tournament->only(['id', 'name', 'status', 'format']),
            winner: $winnerDto,
            bracket: array_values($bracketRounds),
            total_rounds: $totalRounds,
            current_round: $currentRound,
            summary: (object) [
                'total_matches' => $battles->count(),
                'completed_matches' => $battles->where('status', TournamentBattle::STATUS_COMPLETED)->count(),
                'pending_matches' => $battles->where('status', '!=', TournamentBattle::STATUS_COMPLETED)->count(),
            ]
        );

        return response()->json($bracketDto);
    }

    /**
     * Get current user's qualification status for a tournament
     */
    public function qualificationStatus(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['qualified' => false, 'attempt' => null, 'rank' => null]);
        }

        // Check if user has submitted a qualification attempt
        $attempt = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $attempt) {
            return response()->json(['qualified' => false, 'attempt' => null, 'rank' => null]);
        }

        // Compute rank: count how many unique users have better scores or same score with faster duration
        $betterAttempts = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where(function ($q) use ($attempt) {
                $q->where('score', '>', $attempt->score)
                    ->orWhere(function ($q2) use ($attempt) {
                        $q2->where('score', $attempt->score)
                            ->where('duration_seconds', '<', $attempt->duration_seconds ?? PHP_INT_MAX);
                    });
            })
            ->distinct('user_id')
            ->count('user_id');

        $rank = $betterAttempts + 1;

        return response()->json([
            'qualified' => true,
            'attempt' => [
                'score' => $attempt->score,
                'duration_seconds' => $attempt->duration_seconds,
                'created_at' => $attempt->created_at
            ],
            'rank' => $rank,
            'message' => "You are ranked #{$rank}"
        ]);
    }

    /**
     * Submit qualifier attempt (single attempt per user). Grades server-side and records duration.
     */
    public function qualifySubmit(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthorized'], 401);

        // Validate tournament state
        $validationError = $this->validateQualifySubmitState($tournament, $user);
        if ($validationError) {
            return $validationError;
        }

        // Validate and extract request data
        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable',
            'duration_seconds' => 'nullable|integer|min:0'
        ]);

        $questions = $tournament->questions()->get()->keyBy('id');
        $shuffleSeed = (string) $request->input('shuffle_seed', '');

        // Process answers and compute score
        $computedScore = 0.0;
        $answersToStore = [];

        foreach ($data['answers'] as $ans) {
            $answerData = $this->processQualifyAnswer($ans, $questions, $shuffleSeed);
            if (is_array($answerData) && isset($answerData['error'])) {
                return response()->json(['message' => $answerData['error']], 400);
            }

            $answersToStore[] = $answerData;
            $computedScore += (float) ($answerData['points'] ?? 0);
        }

        // Create attempt record
        $attempt = TournamentQualificationAttempt::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'score' => round($computedScore, 2),
            'answers' => $answersToStore,
            'duration_seconds' => $data['duration_seconds'] ?? null
        ]);

        // Update participant pivot score
        $this->updateQualifyParticipantScore($tournament, $user, $attempt);

        // Build leaderboard context
        $leaderboard = $this->buildQualifierLeaderboard($tournament);

        return response()->json([
            'message' => 'Qualification attempt recorded',
            'attempt' => $attempt,
            'leaderboard' => $leaderboard
        ]);
    }

    /**
     * Validate tournament state for qualification submission
     * @return \Illuminate\Http\JsonResponse|null Error response or null if valid
     */
    private function validateQualifySubmitState(Tournament $tournament, \App\Models\User $user): ?\Illuminate\Http\JsonResponse
    {
        $now = now();

        // Check tournament window
        if ($tournament->start_date && $now->lt($tournament->start_date)) {
            return response()->json(['message' => 'Qualification has not started'], 400);
        }
        if ($tournament->end_date && $now->gt($tournament->end_date)) {
            return response()->json(['message' => 'Qualification is closed'], 400);
        }

        // Check tournament status
        if ($tournament->status !== 'upcoming') {
            return response()->json(['message' => 'Qualification period has closed for this tournament'], 400);
        }

        // Check user registration and payment
        $participant = $tournament->participants()->where('user_id', $user->id)->first();
        if (! $participant || ($participant->pivot->status ?? 'pending_payment') !== 'paid') {
            return response()->json(['message' => 'You must be registered and payment must be confirmed to take this qualifier'], 403);
        }

        // Check single attempt policy
        $existing = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->first();
        if ($existing) {
            return response()->json(['message' => 'Only a single qualification attempt is allowed'], 400);
        }

        return null;
    }

    /**
     * Process a single answer from the qualification submission
     * @param array $answerInput The answer from request
     * @param \Illuminate\Support\Collection $questions Keyed by question ID
     * @param string $shuffleSeed Optional shuffle seed for unmapping indices
     * @return array Answer record with points, or error array
     */
    private function processQualifyAnswer(array $answerInput, \Illuminate\Support\Collection $questions, string $shuffleSeed): array
    {
        $qId = (int) ($answerInput['question_id'] ?? 0);
        $given = $answerInput['answer'] ?? null;

        if (! $questions->has($qId)) {
            return ['error' => "Question {$qId} not found for this tournament"];
        }

        $q = $questions->get($qId);

        // Unmap shuffled indices if needed
        if ($shuffleSeed && ! empty($q->options)) {
            $given = $this->unmapShuffledAnswer($given, $q, $shuffleSeed);
        }

        // Resolve correct answers for the marking service
        $correctAnswers = $this->resolveCorrectAnswers($q);

        // Grade the answer
        $marks = (int) ($q->marks ?? 1);
        $isCorrect = $this->markingService->isAnswerCorrect($given, $correctAnswers, $q);
        $points = $isCorrect ? $marks : 0;

        return [
            'question_id' => $qId,
            'answer' => is_array($given) ? $given : (string) ($given ?? ''),
            'points' => round((float) $points, 2)
        ];
    }

    /**
     * Unmap shuffled option indices back to original values
     * @param mixed $given The provided answer (index or value)
     * @param object $question The question model
     * @param string $shuffleSeed The shuffle seed from the response
     * @return mixed The unmapped answer
     */
    private function unmapShuffledAnswer(mixed $given, object $question, string $shuffleSeed): mixed
    {
        $opts = $this->extractOptionsArray($question->options);
        if (empty($opts)) {
            return $given;
        }

        $shuffled = $this->seededShuffle($opts, $shuffleSeed . '::' . $question->id);

        if (is_array($given)) {
            return $this->unmapArrayAnswer($given, $shuffled);
        }

        return $this->unmapSingleAnswer($given, $shuffled);
    }

    /**
     * Extract options array from question (handles array and JSON string formats)
     * @param mixed $options Raw options from question
     * @return array The options array
     */
    private function extractOptionsArray(mixed $options): array
    {
        if (is_array($options)) {
            return $options;
        }

        if (is_string($options)) {
            $decoded = json_decode($options, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Unmap an array of answer indices/values
     * @param array $given The array of answers
     * @param array $shuffled The shuffled options
     * @return array The unmapped answers
     */
    private function unmapArrayAnswer(array $given, array $shuffled): array
    {
        $mapped = [];
        foreach ($given as $g) {
            if (is_numeric($g) && isset($shuffled[(int) $g])) {
                $mapped[] = $this->extractOptionValue($shuffled[(int) $g]);
            } else {
                $mapped[] = $g;
            }
        }
        return $mapped;
    }

    /**
     * Unmap a single answer index/value
     * @param mixed $given The answer
     * @param array $shuffled The shuffled options
     * @return mixed The unmapped answer
     */
    private function unmapSingleAnswer(mixed $given, array $shuffled): mixed
    {
        if (is_numeric($given) && isset($shuffled[(int) $given])) {
            return $this->extractOptionValue($shuffled[(int) $given]);
        }
        return $given;
    }

    /**
     * Extract a display value from an option (tries id, text, body, or returns the option)
     * @param mixed $option The option from the shuffled array
     * @return mixed The extracted value
     */
    private function extractOptionValue(mixed $option): mixed
    {
        if (is_array($option)) {
            return $option['id'] ?? $option['text'] ?? $option['body'] ?? $option;
        }
        return $option;
    }

    /**
     * Resolve the correct answers for a question based on its type
     * @param object $question The question model
     * @return array Array of correct answers
     */
    private function resolveCorrectAnswers(object $question): array
    {
        $correctAnswers = [];

        if ($question->type === 'mcq') {
            if (! is_null($question->correct)) {
                $correctAnswers = [(string) $question->correct];
            } elseif (! empty($question->answers)) {
                $correctAnswers = is_array($question->answers) ? $question->answers : [$question->answers];
            }
        } elseif ($question->type === 'multi') {
            if (is_array($question->corrects)) {
                $correctAnswers = $question->corrects;
            } elseif (is_string($question->corrects)) {
                $decoded = json_decode($question->corrects, true);
                $correctAnswers = is_array($decoded) ? $decoded : [];
            }
        } else {
            $correctAnswers = $question->answers ?? [];
        }

        return $correctAnswers;
    }

    /**
     * Update the participant score in the tournament pivot table
     * @param Tournament $tournament
     * @param \App\Models\User $user
     * @param TournamentQualificationAttempt $attempt
     * @return void
     */
    private function updateQualifyParticipantScore(Tournament $tournament, \App\Models\User $user, TournamentQualificationAttempt $attempt): void
    {
        try {
            $tournament->participants()->updateExistingPivot($user->id, [
                'score' => $attempt->score,
                'completed_at' => now()
            ]);
        } catch (\Exception $_) {
            // non-fatal; continue
        }
    }

    /**
     * Build the top 10 qualifier leaderboard
     * @param Tournament $tournament
     * @return \Illuminate\Support\Collection
     */
    private function buildQualifierLeaderboard(Tournament $tournament): \Illuminate\Support\Collection
    {
        return TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->orderByDesc('score')
            ->orderBy('duration_seconds')
            ->limit(10)
            ->get()
            ->map(function($a) {
                return [
                    'user_id' => $a->user_id,
                    'score' => $a->score,
                    'duration_seconds' => $a->duration_seconds,
                    'created_at' => $a->created_at
                ];
            });
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

            $correct = $q->answers;
            if (is_string($correct)) {
                $decoded = json_decode($correct, true);
                $correct = is_array($decoded) ? $decoded : [$decoded];
            } elseif (!is_array($correct)) {
                $correct = [$correct];
            }

            $questions[] = [
                'question_id' => $q->id,
                'body' => $q->body,
                'initiator' => $initiator,
                'opponent' => $opponent,
                'correct' => $correct,
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

        // Use centralized service for subscription validation
        $subValidation = \App\Services\SubscriptionLimitService::validateSubscriptionAccess($user);
        
        if (!$subValidation['allowed']) {
            return response()->json([
                'ok' => false, 
                'message' => $subValidation['message'] ?? 'Subscription or tournament purchase required'
            ], 403);
        }

        // Check one-off purchase for the tournament (pay once for the whole tournament)
        $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'tournament')
            ->where('item_id', $tournament->id)
            ->where('status', 'confirmed')
            ->exists();

        // Must have either subscription or one-off tournament purchase
        if (!$subValidation['subscription'] && !$hasOneOff) {
            return response()->json(['ok' => false, 'message' => 'Subscription or tournament purchase required'], 403);
        }

        // Enforce package limits if subscription is active
        $activeSub = $subValidation['subscription'];
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
                    $remaining = max(0, intval($limit) - intval($used));
                    return response()->json([
                        'ok' => false,
                        'code' => 'limit_reached',
                        'limit' => [
                            'type' => 'battle_results',
                            'value' => intval($limit),
                            'used' => intval($used),
                            'remaining' => $remaining,
                            'subscription_type' => $subValidation['subscription_type']
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
            'status' => $participant->pivot->status ?? 'paid'
        ]);
    }

    /**
     * Save draft answers for a battle (auto-save functionality)
     */
    public function saveDraft(Request $request, TournamentBattle $battle)
    {
        $user = $request->user();
        
        // Verify user is participant
        if ($battle->player1_id !== $user->id && $battle->player2_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        // Only allow draft saves during in-progress or scheduled state
        if (!in_array($battle->status, [TournamentBattle::STATUS_IN_PROGRESS, TournamentBattle::STATUS_SCHEDULED])) {
            return response()->json(['message' => 'Battle is no longer accepting drafts'], 400);
        }

        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            // answer may be an index (int), an array of indices, or a string/value depending on client
            'answers.*.answer' => 'nullable',
            'current_question_index' => 'nullable|integer|min:0',
            'time_remaining' => 'nullable|integer|min:0',
            'shuffle_seed' => 'nullable|string'
        ]);

        $shuffleSeed = $data['shuffle_seed'] ?? null;

        // If answers are provided as option values (not indices) but shuffle_seed is available,
        // attempt to remap them to indices relative to the shuffled options for storage.
        $questions = $battle->questions()->get()->keyBy('id');
        $normalizedAnswers = [];
        foreach ($data['answers'] as $a) {
            $qid = intval($a['question_id'] ?? 0);
            $ans = $a['answer'] ?? null;

            // If shuffle_seed present and answer is not numeric/array-of-numeric, try to map to index
            if ($shuffleSeed && $questions->has($qid)) {
                $q = $questions->get($qid);
                $opts = [];
                if (is_array($q->options)) {
                    $opts = $q->options;
                } elseif (is_string($q->options)) {
                    $decoded = json_decode((string) $q->options, true);
                    if (is_array($decoded)) $opts = $decoded;
                }

                if (!empty($opts)) {
                    $shuffled = $this->seededShuffle($opts, $shuffleSeed . '::' . $q->id);
                    // If answer is array, map each value to index where possible
                    if (is_array($ans)) {
                        $mapped = [];
                        foreach ($ans as $v) {
                            $found = false;
                            foreach ($shuffled as $idx => $opt) {
                                $val = is_array($opt) ? ($opt['id'] ?? $opt['text'] ?? $opt['body'] ?? $opt) : $opt;
                                if ((string)$val === (string)$v) { $mapped[] = $idx; $found = true; break; }
                            }
                            if (! $found) $mapped[] = $v;
                        }
                        $ans = $mapped;
                    } else {
                        // single value
                        foreach ($shuffled as $idx => $opt) {
                            $val = is_array($opt) ? ($opt['id'] ?? $opt['text'] ?? $opt['body'] ?? $opt) : $opt;
                            if ((string)$val === (string)$ans) { $ans = $idx; break; }
                        }
                    }
                }
            }

            $normalizedAnswers[] = [
                'question_id' => $qid,
                'answer' => $ans,
            ];
        }

        // Store draft in cache (expires in 24 hours)
        $draftKey = "tournament_battle_draft_{$battle->id}_player_{$user->id}";
        
        Cache::put($draftKey, [
            'battle_id' => $battle->id,
            'player_id' => $user->id,
            'answers' => $normalizedAnswers,
            'current_question_index' => $data['current_question_index'] ?? 0,
            'time_remaining' => $data['time_remaining'] ?? null,
            'shuffle_seed' => $shuffleSeed,
            'saved_at' => now()->toIso8601String(),
        ], now()->addHours(24));

        return response()->json([
            'message' => 'Draft saved successfully',
            'saved_at' => now(),
        ]);
    }

    /**
     * Load draft answers for a battle
     */
    public function loadDraft(Request $request, TournamentBattle $battle)
    {
        $user = $request->user();
        
        // Verify user is participant
        if ($battle->player1_id !== $user->id && $battle->player2_id !== $user->id) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        // Retrieve draft from cache
        $draftKey = "tournament_battle_draft_{$battle->id}_player_{$user->id}";
        $draft = Cache::get($draftKey);

        if (!$draft) {
            return response()->json(['draft' => null], 200);
        }

        return response()->json([
            'draft' => $draft
        ], 200);
    }

    /**
     * Deterministic shuffle helper using a simple LCG seeded with crc32 of the seed string.
     * Returns a new array with items shuffled deterministically for the given seed.
     */
    protected function seededShuffle(array $items, string $seed): array
    {
        $copy = array_values($items);
        $state = crc32($seed) & 0xFFFFFFFF;
        $lcg = function() use (&$state) {
            // 32-bit LCG
            $state = (1103515245 * $state + 12345) & 0x7fffffff;
            return $state / 2147483647;
        };
        $n = count($copy);
        for ($i = $n - 1; $i > 0; $i--) {
            $r = $lcg();
            $j = (int) floor($r * ($i + 1));
            $tmp = $copy[$i];
            $copy[$i] = $copy[$j];
            $copy[$j] = $tmp;
        }
        return $copy;
    }
}
