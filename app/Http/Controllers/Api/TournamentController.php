<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentQualificationAttempt;
use App\Services\AchievementService;
use App\Services\QuestionMarkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TournamentController extends Controller
{
    private const SIMPLE_FLOW_MAX_ATTEMPTS = 3;

    protected AchievementService $achievementService;
    protected QuestionMarkingService $markingService;

    public function __construct(AchievementService $achievementService, QuestionMarkingService $markingService)
    {
        $this->achievementService = $achievementService;
        $this->markingService = $markingService;
        $this->middleware('auth:sanctum')->except(['index', 'show']);
        $this->middleware('throttle:60,1')->only(['join', 'qualifySubmit']);
    }

    public function index(Request $request)
    {
        $query = Tournament::with(['subject', 'topic', 'grade']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }
        if ($subjectId = $request->get('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        if ($gradeId = $request->get('grade_id')) {
            $query->where('grade_id', $gradeId);
        }

        $tournaments = $query->withCount('participants')->latest()->paginate(20);
        return response()->json($tournaments);
    }

    public function show(Tournament $tournament)
    {
        $this->maybeFinalizeSimpleFlowTournament($tournament);

        $tournament->load(['subject', 'topic', 'grade', 'level', 'participants', 'questions', 'winner', 'sponsor']);
        $user = Auth::user();

        $isParticipant = $user ? $tournament->participants()->where('user_id', $user->id)->exists() : false;
        $tournament->is_participant = $isParticipant;

        $eligibility = [
            'can_join' => false,
            'reason' => null,
        ];

        if ($user) {
            try {
                $gate = app(\Illuminate\Contracts\Auth\Access\Gate::class);
                $inspect = $gate->forUser($user)->inspect('join', $tournament);
                $eligibility['can_join'] = $inspect->allowed();
                $eligibility['reason'] = $inspect->message() ?? null;
            } catch (\Throwable $e) {
                $eligibility['can_join'] = false;
                $eligibility['reason'] = $e->getMessage();
            }
        } else {
            $eligibility['can_join'] = false;
            $eligibility['reason'] = 'authentication_required';
        }

        try {
            $shuffleSeed = bin2hex(random_bytes(6));
        } catch (\Exception $_) {
            $shuffleSeed = (string) time();
        }

        if ($tournament->relationLoaded('questions') && $tournament->questions->isNotEmpty()) {
            $questions = $tournament->questions->map(function ($q) use ($shuffleSeed) {
                $opts = [];
                if (is_array($q->options)) {
                    $opts = $q->options;
                } elseif (is_string($q->options)) {
                    $decoded = json_decode((string) $q->options, true);
                    if (is_array($decoded)) {
                        $opts = $decoded;
                    }
                }

                if (!empty($opts)) {
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
            $tournament->setRelation('questions', collect($shuffledQuestions));
        }

        return response()->json([
            'ok' => true,
            'tournament' => $tournament,
            'winner' => $tournament->winner ?? null,
            'eligibility' => $eligibility,
            'shuffle_seed' => $shuffleSeed,
        ]);
    }

    public function join(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        $this->authorize('join', $tournament);

        if ($tournament->status !== 'upcoming' && $tournament->status !== 'active') {
            return response()->json(['message' => 'Tournament is not open for registration'], 400);
        }

        $paymentRef = $request->input('payment_reference');

        return DB::transaction(function () use ($tournament, $user, $paymentRef) {
            $lockedTournament = Tournament::lockForUpdate()->find($tournament->id);

            $fee = $lockedTournament->entry_fee && floatval($lockedTournament->entry_fee) > 0;
            $activeSub = \App\Models\Subscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->where(function ($q) {
                    $now = now();
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->orderByDesc('started_at')
                ->first();

            $hasOneOff = false;
            if ($paymentRef) {
                $purchase = \App\Models\OneOffPurchase::where(function ($q) use ($paymentRef) {
                    $q->where('id', $paymentRef)->orWhere('gateway_meta->tx', $paymentRef);
                })->first();

                if ($purchase && $purchase->user_id === $user->id && $purchase->item_type === 'tournament' && $purchase->item_id == $lockedTournament->id && $purchase->status === 'confirmed') {
                    $hasOneOff = true;
                }
            }

            if (!$hasOneOff) {
                $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
                    ->where('item_type', 'tournament')
                    ->where('item_id', $lockedTournament->id)
                    ->where('status', 'confirmed')
                    ->exists();
            }

            $isPaid = !$fee || $hasOneOff || ($activeSub && (bool) ($lockedTournament->open_to_subscribers ?? false));

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

            $status = $isPaid ? 'paid' : 'pending_payment';

            DB::table('tournament_participants')->insert([
                'tournament_id' => $lockedTournament->id,
                'user_id' => $user->id,
                'status' => $status,
                'requested_at' => now(),
                'approved_at' => $status === 'paid' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->achievementService->checkAchievements($user->id, [
                'type' => 'tournament_joined',
                'tournament_id' => $lockedTournament->id,
            ]);

            if ($status === 'pending_payment') {
                return response()->json([
                    'message' => 'Registration recorded; payment pending',
                    'status' => 'pending_payment',
                    'code' => 'pending_payment',
                    'amount' => (float) $lockedTournament->entry_fee,
                    'item_type' => 'tournament',
                    'item_id' => $lockedTournament->id,
                ], 202);
            }

            return response()->json(['message' => 'Successfully joined tournament', 'status' => 'paid']);
        });
    }

    public function approveRegistration(Request $request, Tournament $tournament, $userId)
    {
        $this->authorize('approveRegistration', Tournament::class);

        if (!$tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        try {
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'paid',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
            ]);

            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'paid'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to mark registration as paid'], 500);
        }

        return response()->json(['message' => 'Registration marked as paid']);
    }

    public function rejectRegistration(Request $request, Tournament $tournament, $userId)
    {
        $this->authorize('rejectRegistration', Tournament::class);

        if (!$tournament->participants()->where('user_id', $userId)->exists()) {
            return response()->json(['message' => 'Registration not found'], 404);
        }

        try {
            $tournament->participants()->updateExistingPivot($userId, [
                'status' => 'rejected',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
            ]);

            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->notify(new \App\Notifications\TournamentRegistrationStatusChanged($tournament, 'rejected'));
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to reject registration'], 500);
        }

        return response()->json(['message' => 'Registration rejected']);
    }

    public function leaderboard(Tournament $tournament)
    {
        $this->maybeFinalizeSimpleFlowTournament($tournament);
        $attemptCounts = $this->attemptCountsByUser($tournament);

        $latestAttempts = $this->latestAttemptsQuery($tournament)
            ->with('user:id,name,avatar,avatar_url')
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->get();

        $leaderboard = $latestAttempts->values()->map(function ($attempt, $index) use ($attemptCounts) {
            $user = $attempt->user;
            $userId = $user->id ?? $attempt->user_id;
            $attemptsUsed = (int) ($attemptCounts[$userId] ?? 0);

            return [
                'id' => $userId,
                'name' => $user->name ?? null,
                'avatar_url' => $user->avatar_url ?? null,
                'avatar' => $user->avatar ?? null,
                'points' => (float) $attempt->score,
                'duration_seconds' => $attempt->duration_seconds,
                'rank' => $index + 1,
                'attempts_used' => $attemptsUsed,
                'attempts_remaining' => max(0, self::SIMPLE_FLOW_MAX_ATTEMPTS - $attemptsUsed),
                'completed_at' => $attempt->created_at,
            ];
        });

        return response()->json([
            'tournament' => $tournament->only(['id', 'name', 'status']),
            'leaderboard' => $leaderboard,
            'max_attempts' => self::SIMPLE_FLOW_MAX_ATTEMPTS,
            'is_qualifier_phase' => false,
        ]);
    }

    public function qualifierLeaderboard(Tournament $tournament, Request $request)
    {
        $this->maybeFinalizeSimpleFlowTournament($tournament);
        $perPage = $request->get('per_page', 50);

        $attempts = $this->latestAttemptsQuery($tournament)
            ->with('user:id,name,email,avatar_url,social_avatar')
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->paginate($perPage);

        $attemptCounts = $this->attemptCountsByUser($tournament);

        return response()->json([
            'data' => $attempts->map(function ($attempt) use ($attemptCounts) {
                $user = $attempt->user;
                $attemptsUsed = (int) ($attemptCounts[$attempt->user_id] ?? 0);

                return [
                    'id' => $attempt->id,
                    'user_id' => $attempt->user_id,
                    'user_name' => $user->name ?? null,
                    'user_email' => $user->email ?? null,
                    'avatar_url' => $user->avatar_url ?? null,
                    'avatar' => $user->avatar ?? null,
                    'user_avatar' => $user->avatar ?? null,
                    'image' => $user->avatar ?? null,
                    'picture' => $user->avatar ?? null,
                    'score' => $attempt->score,
                    'duration_seconds' => $attempt->duration_seconds,
                    'status' => $attempt->status ?? 'completed',
                    'attempts_used' => $attemptsUsed,
                    'attempts_remaining' => max(0, self::SIMPLE_FLOW_MAX_ATTEMPTS - $attemptsUsed),
                    'completed_at' => $attempt->created_at,
                ];
            }),
            'pagination' => [
                'total' => $attempts->total(),
                'count' => $attempts->count(),
                'per_page' => $attempts->perPage(),
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
            ],
        ]);
    }

    public function qualificationStatus(Request $request, Tournament $tournament)
    {
        $this->maybeFinalizeSimpleFlowTournament($tournament);
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'qualified' => false,
                'attempt' => null,
                'rank' => null,
                'attempts_used' => 0,
                'attempts_remaining' => self::SIMPLE_FLOW_MAX_ATTEMPTS,
            ]);
        }

        $attemptsUsed = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->count();

        $attempt = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        if (!$attempt) {
            return response()->json([
                'qualified' => false,
                'attempt' => null,
                'rank' => null,
                'attempts_used' => 0,
                'attempts_remaining' => self::SIMPLE_FLOW_MAX_ATTEMPTS,
            ]);
        }

        $orderedUserIds = $this->latestAttemptsQuery($tournament)
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->pluck('user_id')
            ->values()
            ->all();

        $rankIdx = array_search($user->id, $orderedUserIds, true);
        $rank = $rankIdx === false ? null : $rankIdx + 1;

        return response()->json([
            'qualified' => true,
            'attempt' => [
                'score' => $attempt->score,
                'duration_seconds' => $attempt->duration_seconds,
                'created_at' => $attempt->created_at,
            ],
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => max(0, self::SIMPLE_FLOW_MAX_ATTEMPTS - $attemptsUsed),
            'rank' => $rank,
            'message' => $rank ? "You are ranked #{$rank}" : null,
        ]);
    }

    public function qualifySubmit(Request $request, Tournament $tournament)
    {
        $this->maybeFinalizeSimpleFlowTournament($tournament);
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validationError = $this->validateQualifySubmitState($tournament, $user);
        if ($validationError) {
            return $validationError;
        }

        $data = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.answer' => 'nullable',
            'duration_seconds' => 'nullable|integer|min:0',
        ]);

        $questions = $tournament->questions()->get()->keyBy('id');
        $shuffleSeed = (string) $request->input('shuffle_seed', '');

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

        $attempt = TournamentQualificationAttempt::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'score' => round($computedScore, 2),
            'answers' => $answersToStore,
            'duration_seconds' => $data['duration_seconds'] ?? null,
        ]);

        $this->updateQualifyParticipantScore($tournament, $user, $attempt);

        $leaderboard = $this->buildQualifierLeaderboard($tournament);
        $attemptsUsed = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->count();

        $rank = $leaderboard->search(function ($row) use ($user) {
            return (int) ($row['user_id'] ?? 0) === (int) $user->id;
        });
        $rank = $rank === false ? null : $rank + 1;

        $this->maybeFinalizeSimpleFlowTournament($tournament->fresh());

        return response()->json([
            'message' => 'Tournament attempt recorded',
            'attempt' => $attempt,
            'rank' => $rank,
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => max(0, self::SIMPLE_FLOW_MAX_ATTEMPTS - $attemptsUsed),
            'leaderboard' => $leaderboard,
            'max_attempts' => self::SIMPLE_FLOW_MAX_ATTEMPTS,
        ]);
    }

    public function registrationStatus(Request $request, Tournament $tournament)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['isRegistered' => false]);
        }

        $participant = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$participant) {
            return response()->json(['isRegistered' => false]);
        }

        return response()->json([
            'isRegistered' => true,
            'status' => $participant->pivot->status ?? 'paid',
        ]);
    }

    private function validateQualifySubmitState(Tournament $tournament, \App\Models\User $user): ?\Illuminate\Http\JsonResponse
    {
        $now = now();

        if ($tournament->start_date && $now->lt($tournament->start_date)) {
            return response()->json(['message' => 'Qualification has not started'], 400);
        }
        if ($tournament->end_date && $now->gt($tournament->end_date)) {
            return response()->json(['message' => 'Qualification is closed'], 400);
        }
        if (!in_array($tournament->status, ['upcoming', 'active'], true)) {
            return response()->json(['message' => 'Tournament is not accepting attempts'], 400);
        }

        $participant = $tournament->participants()->where('user_id', $user->id)->first();
        if (!$participant || ($participant->pivot->status ?? 'pending_payment') !== 'paid') {
            return response()->json(['message' => 'You must be registered and payment must be confirmed before submitting your tournament attempt'], 403);
        }

        $attemptsUsed = TournamentQualificationAttempt::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->count();
        if ($attemptsUsed >= self::SIMPLE_FLOW_MAX_ATTEMPTS) {
            return response()->json(['message' => 'Maximum tournament attempts reached'], 400);
        }

        return null;
    }

    private function processQualifyAnswer(array $answerInput, \Illuminate\Support\Collection $questions, string $shuffleSeed): array
    {
        $qId = (int) ($answerInput['question_id'] ?? 0);
        $given = $answerInput['answer'] ?? null;

        if (!$questions->has($qId)) {
            return ['error' => "Question {$qId} not found for this tournament"];
        }

        $q = $questions->get($qId);
        if ($shuffleSeed && !empty($q->options)) {
            $given = $this->unmapShuffledAnswer($given, $q, $shuffleSeed);
        }

        $correctAnswers = $this->resolveCorrectAnswers($q);
        $marks = (int) ($q->marks ?? 1);
        $isCorrect = $this->markingService->isAnswerCorrect($given, $correctAnswers, $q);
        $points = $isCorrect ? $marks : 0;

        return [
            'question_id' => $qId,
            'answer' => is_array($given) ? $given : (string) ($given ?? ''),
            'points' => round((float) $points, 2),
        ];
    }

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

    private function unmapSingleAnswer(mixed $given, array $shuffled): mixed
    {
        if (is_numeric($given) && isset($shuffled[(int) $given])) {
            return $this->extractOptionValue($shuffled[(int) $given]);
        }
        return $given;
    }

    private function extractOptionValue(mixed $option): mixed
    {
        if (is_array($option)) {
            return $option['id'] ?? $option['text'] ?? $option['body'] ?? $option;
        }
        return $option;
    }

    private function resolveCorrectAnswers(object $question): array
    {
        $correctAnswers = [];

        if ($question->type === 'mcq') {
            if (!is_null($question->correct)) {
                $correctAnswers = [(string) $question->correct];
            } elseif (!empty($question->answers)) {
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

    private function updateQualifyParticipantScore(Tournament $tournament, \App\Models\User $user, TournamentQualificationAttempt $attempt): void
    {
        try {
            $tournament->participants()->updateExistingPivot($user->id, [
                'score' => $attempt->score,
                'completed_at' => now(),
            ]);
        } catch (\Exception $_) {
        }
    }

    private function buildQualifierLeaderboard(Tournament $tournament): \Illuminate\Support\Collection
    {
        $attemptCounts = $this->attemptCountsByUser($tournament);

        return $this->latestAttemptsQuery($tournament)
            ->with('user:id,name,avatar_url,social_avatar')
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->map(function ($a, $index) use ($attemptCounts) {
                $user = $a->user;
                $attemptsUsed = (int) ($attemptCounts[$a->user_id] ?? 0);

                return [
                    'user_id' => $a->user_id,
                    'name' => $user->name ?? null,
                    'avatar_url' => $user->avatar_url ?? null,
                    'avatar' => $user->avatar ?? null,
                    'image' => $user->avatar ?? null,
                    'picture' => $user->avatar ?? null,
                    'rank' => $index + 1,
                    'score' => (float) $a->score,
                    'duration_seconds' => $a->duration_seconds,
                    'attempts_used' => $attemptsUsed,
                    'attempts_remaining' => max(0, self::SIMPLE_FLOW_MAX_ATTEMPTS - $attemptsUsed),
                    'created_at' => $a->created_at,
                ];
            });
    }

    private function latestAttemptsQuery(Tournament $tournament): \Illuminate\Database\Eloquent\Builder
    {
        $latestIdsSub = TournamentQualificationAttempt::query()
            ->selectRaw('MAX(id) as id')
            ->where('tournament_id', $tournament->id)
            ->groupBy('user_id');

        return TournamentQualificationAttempt::query()
            ->where('tournament_id', $tournament->id)
            ->whereIn('id', $latestIdsSub);
    }

    private function attemptCountsByUser(Tournament $tournament): array
    {
        return TournamentQualificationAttempt::query()
            ->where('tournament_id', $tournament->id)
            ->select('user_id', DB::raw('COUNT(*) as attempts_used'))
            ->groupBy('user_id')
            ->pluck('attempts_used', 'user_id')
            ->map(function ($v) {
                return (int) $v;
            })
            ->toArray();
    }

    private function maybeFinalizeSimpleFlowTournament(Tournament $tournament): void
    {
        if ($tournament->status === 'completed') {
            return;
        }

        if ($tournament->battles()->exists()) {
            return;
        }

        if (!$tournament->end_date || now()->lt($tournament->end_date)) {
            return;
        }

        $winnerAttempt = $this->latestAttemptsQuery($tournament)
            ->orderByDesc('score')
            ->orderByRaw('CASE WHEN duration_seconds IS NULL THEN 2147483647 ELSE duration_seconds END ASC')
            ->orderBy('id')
            ->first();

        $tournament->status = 'completed';
        $tournament->winner_id = $winnerAttempt?->user_id;
        $tournament->save();
    }

    protected function seededShuffle(array $items, string $seed): array
    {
        $copy = array_values($items);
        $state = crc32($seed) & 0xFFFFFFFF;
        $lcg = function () use (&$state) {
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
