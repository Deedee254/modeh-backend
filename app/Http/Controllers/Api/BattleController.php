<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\BattleSubmission;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Quiz;
use App\Services\AchievementService;

class BattleController extends Controller
{
    protected $achievementService;

    public function __construct(AchievementService $achievementService)
    {
        $this->achievementService = $achievementService;
    }
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->only(['name']);
        $data['initiator_id'] = $user->id;
// Ensure opponent_id is set to something if DB requires it (tests use sqlite schema)
    $data['opponent_id'] = $request->input('opponent_id', $user->id);
        $data['status'] = 'waiting';
        if ($request->has('settings')) {
            // persist settings as provided (will be cast to array by model)
            $data['settings'] = $request->input('settings');
        }

        $battle = Battle::create($data);

        // If frontend requested atomic attach, perform selection now
        if ($request->boolean('attach_questions')) {
            // Validate required filters before selection
            $this->validateAttachPayload($request);

            $this->selectAndAttachQuestions($battle, $request->all());
            $battle->load('questions');
        }

        // If frontend provided a total time in settings, compute and persist time_per_question so
        // the battle payload immediately contains timing for frontends to enforce.
        $settings = is_array($battle->settings) ? $battle->settings : (array) ($battle->settings ?? []);
        $totalTime = $request->input('settings.time_total_seconds') ?? $request->input('settings.total_time_seconds') ?? $request->input('time_total_seconds') ?? $request->input('total_time_seconds') ?? null;
        if ($totalTime && isset($settings['question_count']) && intval($settings['question_count']) > 0) {
            $count = intval($settings['question_count']);
            $per = (int) floor(intval($totalTime) / $count);
            $settings['time_per_question'] = $per;
            $settings['time_total_seconds'] = intval($totalTime);
            $battle->settings = $settings;
            $battle->save();
            $battle->load('questions');
        }

    // return created battle including uuid for sharing
    return response()->json($battle);
    }

    public function index(Request $request)
    {
        // Only show waiting battles that are open for joining
        // select explicit existing columns from quizees to avoid SQL errors (use first_name)
        // NOTE: there is no `players` relationship on Battle; eager-load initiator/opponent
        $battles = Battle::with('initiator:id,first_name,profile', 'opponent:id,first_name,profile')
            ->where('status', 'waiting')
            ->where(function ($query) {
                // A battle is joinable if opponent_id is null, or if it's the same as initiator_id (placeholder)
                $query->whereNull('opponent_id')
                      ->orWhereColumn('opponent_id', 'initiator_id');
            })
            ->latest()
            ->limit(20)
            ->get();

        // Build a simple players collection from initiator/opponent for frontend compatibility
        $battles->each(function ($battle) {
            $players = collect();
            if ($battle->initiator) $players->push($battle->initiator);
            // If opponent is set and not just the placeholder equal to initiator, include it
            if ($battle->opponent && $battle->opponent_id !== $battle->initiator_id) $players->push($battle->opponent);
            // set as a loaded relation so JSON serialization includes it
            $battle->setRelation('players', $players);
        });

        return response()->json(['battles' => $battles]);
    }

    public function myBattles(Request $request)
    {
        $user = $request->user();
        $battles = Battle::where('initiator_id', $user->id)
            ->orWhere('opponent_id', $user->id)
            ->with(['initiator:id,first_name,profile', 'opponent:id,first_name,profile'])
            ->latest()->paginate(15);
        return response()->json($battles);
    }

    public function show(Request $request, Battle $battle)
    {
        // Conditionally load questions. Default to true, but allow disabling.
        if ($request->boolean('with_questions', true)) {
            $battle->load('questions');
        }
        // expose time_per_question at top-level for frontend convenience
        $settings = is_array($battle->settings) ? $battle->settings : (array) ($battle->settings ?? []);
        if (isset($settings['time_per_question'])) {
            $battle->time_per_question = $settings['time_per_question'];
        }
        return response()->json($battle);
    }

    /**
     * Allow an authenticated user to join a pending battle as the opponent.
     * If opponent_id is already set, return existing battle. Broadcast join event when successful.
     */
    public function join(Request $request, Battle $battle)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // If user is already a participant, return
        if (in_array($user->id, [$battle->initiator_id, $battle->opponent_id])) {
            return response()->json(['ok' => true, 'battle' => $battle]);
        }

        // Only allow joining if battle is waiting or has no opponent
        if ($battle->opponent_id && $battle->opponent_id !== $battle->initiator_id) {
            return response()->json(['message' => 'Battle already has an opponent'], 400);
        }

        $battle->opponent_id = $user->id;
        $battle->status = 'waiting';
        $battle->save();

        // broadcast participant joined event
        try {
            event(new \App\Events\BattleParticipantJoined($battle, $user->id));
        } catch (\Exception $e) {
            // ignore broadcast errors
        }

        return response()->json(['ok' => true, 'battle' => $battle]);
    }

    public function attachQuestions(Request $request, Battle $battle)
    {
        // Only allow the initiator to attach questions for now
        $user = $request->user();
        if ($battle->initiator_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Validate payload when attaching questions
        $this->validateAttachPayload($request);

        $questions = $this->selectAndAttachQuestions($battle, $request->all());

        return response()->json(['attached' => count($questions), 'questions' => $questions]);
    }

    /**
     * Select questions based on params and attach them to the battle (persisted order).
     * Returns the selected collection.
     *
     * @param \App\Models\Battle $battle
     * @param array $params
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function selectAndAttachQuestions(Battle $battle, array $params)
    {
        // Support new settings shape: settings:{ grade_id, subject_id, topic_id, difficulty, question_count }
        $settings = $params['settings'] ?? [];
        // normalize keys from top-level or settings
        $perPage = intval($params['question_count'] ?? $params['number_of_questions'] ?? $settings['question_count'] ?? $settings['number_of_questions'] ?? $params['per_page'] ?? $settings['per_page'] ?? 10);
        $topic = $params['topic_id'] ?? $settings['topic_id'] ?? $params['topic'] ?? $settings['topic'] ?? null;
        $difficulty = $params['difficulty'] ?? $settings['difficulty'] ?? null;
        $grade = $params['grade_id'] ?? $settings['grade_id'] ?? $params['grade'] ?? $settings['grade'] ?? null;
    $level = $params['level_id'] ?? $settings['level_id'] ?? null;
        $subject = $params['subject_id'] ?? $settings['subject_id'] ?? null;
        $random = $params['random'] ?? $settings['random'] ?? 1;

        // Persist the settings back to the battle so the selection can be reproduced later
        $persistable = array_filter([
            'grade_id' => $grade,
            'level_id' => $level,
            'subject_id' => $subject,
            'topic_id' => $topic,
            'difficulty' => $difficulty,
            'question_count' => $perPage,
            'random' => (bool) $random,
        ], function ($v) { return $v !== null && $v !== ''; });
        if (!empty($persistable)) {
            $battle->settings = array_merge(is_array($battle->settings) ? $battle->settings : [], $persistable);
            $battle->save();
        }

    $q = Question::query();
        // We no longer use `for_battle` filter: the bank determines eligibility.
    if ($topic && Schema::hasColumn('questions', 'topic_id')) $q->where('topic_id', $topic);
    if ($subject && Schema::hasColumn('questions', 'subject_id')) $q->where('subject_id', $subject);
    if ($difficulty && Schema::hasColumn('questions', 'difficulty')) $q->where('difficulty', $difficulty);
    if ($grade && Schema::hasColumn('questions', 'grade_id')) $q->where('grade_id', $grade);

        // If level filter provided, constrain questions to grades that belong to that level
        if ($level) {
            try {
                if (Schema::hasTable('grades') && Schema::hasColumn('grades', 'level_id') && Schema::hasColumn('questions', 'grade_id')) {
                    $gradeIds = \App\Models\Grade::where('level_id', $level)->pluck('id')->toArray();
                    if (!empty($gradeIds)) {
                        $q->whereIn('grade_id', $gradeIds);
                    } else {
                        // no grades found for level — ensure no results to respect the filter
                        $q->whereRaw('0 = 1');
                    }
                }
            } catch (\Throwable $_) {
                // ignore failures and fall back to unfiltered behavior
            }
        }

        if ($random) {
            $questions = $q->inRandomOrder()->limit($perPage)->get();
        } else {
            $questions = $q->limit($perPage)->get();
        }

        // If no questions matched the filters, fall back to random questions from the full bank
        if ($questions->isEmpty()) {
            $questions = Question::inRandomOrder()->limit($perPage)->get();
        }

        // attach with position (replace any existing attachments)
        $battle->questions()->detach();
        $attachData = [];
        foreach ($questions as $i => $question) {
            $attachData[$question->id] = ['position' => $i];
        }
        if (!empty($attachData)) {
            $battle->questions()->attach($attachData);
        }

        return $questions;
    }

    /**
     * Validate payload used when attaching questions to a battle.
     * Ensures that when attach_questions is requested, at least one taxonomy filter
     * (grade_id, subject_id, topic_id) or difficulty is provided and question_count is a positive integer.
     */
    private function validateAttachPayload(Request $request)
    {
        $payload = $request->all();
        $settings = $payload['settings'] ?? [];

        // Merge top-level and settings for validation
        $data = array_merge($settings, $payload);

        $rules = [
            'question_count' => 'nullable|integer|min:1',
            'number_of_questions' => 'nullable|integer|min:1',
            'settings.question_count' => 'nullable|integer|min:1',
        ];

        // require at least one of these when attaching
    $hasFilter = isset($data['grade_id']) || isset($data['subject_id']) || isset($data['topic_id']) || isset($data['difficulty']) || isset($data['topic']) || isset($data['grade']) || isset($data['level_id']);

        if (!$hasFilter) {
            abort(response()->json(['message' => 'At least one filter (grade_id, subject_id, topic_id or difficulty) is required to attach questions'], 422));
        }

        // Validate counts
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            abort(response()->json(['message' => 'Invalid payload for attaching questions', 'errors' => $validator->errors()], 422));
        }
    }

    /**
     * Accept a player's answers for a battle, score them and mark the battle completed.
     * Expected payload: { answers: [{ question_id, selected, time_taken }], meta: { score? } }
     */
    public function submit(Request $request, Battle $battle)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // ensure the user is a participant
        if (!in_array($user->id, [$battle->initiator_id, $battle->opponent_id])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

    $payload = $request->validate(['answers' => 'required|array', 'meta' => 'nullable|array']);
    $answers = $payload['answers'] ?? [];
    $defer = $request->boolean('defer_marking', false);

        // If this participant already submitted, return existing battle result (idempotent)
        $isInitiator = $user->id === $battle->initiator_id;
        $pointsField = $isInitiator ? 'initiator_points' : 'opponent_points';
        if (!is_null($battle->{$pointsField})) {
            return response()->json(['ok' => true, 'battle' => $battle]);
        }

        // Score answers and persist per-question submissions using a transaction
        $questionMap = $battle->questions()->get()->keyBy('id');

        $detailed = [];
        $total = count($answers);
        $correct = 0;

        DB::transaction(function () use ($answers, $user, $battle, $questionMap, &$detailed, &$correct, $defer) {
            foreach ($answers as $a) {
                $qid = intval($a['question_id'] ?? 0);
                $selected = $a['selected'] ?? null;
                $timeTaken = $a['time_taken'] ?? null;
                $q = $questionMap->get($qid) ?? \App\Models\Question::find($qid);
                if (!$q) continue;

                $isCorrect = false;
                $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
                if (is_array($selected)) {
                    $isCorrect = array_values($selected) == array_values($correctAnswers);
                } else {
                    $isCorrect = in_array($selected, $correctAnswers);
                }

                // Persist or update the per-question submission
                BattleSubmission::updateOrCreate([
                    'battle_id' => $battle->id,
                    'user_id' => $user->id,
                    'question_id' => $qid,
                ], [
                    'selected' => $selected,
                    'time_taken' => $timeTaken,
                    // If deferring marking, do not set correct_flag yet; mark endpoint will compute it
                    'correct_flag' => $defer ? null : $isCorrect,
                ]);

                if ($isCorrect && !$defer) $correct++;
                $detailed[] = [
                    'question_id' => $qid,
                    'body' => $q->body,
                    'selected' => $selected,
                    'correct' => $correctAnswers,
                    'time_taken' => $timeTaken,
                    'correct_flag' => $defer ? null : $isCorrect,
                ];
            }

            // If not deferring, recompute aggregated points from persisted submissions for both participants
            if (!$defer) {
                $initiatorCorrect = $battle->submissions()->where('user_id', $battle->initiator_id)->where('correct_flag', true)->count();
                $opponentCorrect = $battle->submissions()->where('user_id', $battle->opponent_id)->where('correct_flag', true)->count();

                $battle->initiator_points = $initiatorCorrect;
                $battle->opponent_points = $opponentCorrect;

                // If both sides have now submitted (or opponent already had points), determine winner
                if (!is_null($battle->initiator_points) && !is_null($battle->opponent_points)) {
                    if ($battle->initiator_points > $battle->opponent_points) $battle->winner_id = $battle->initiator_id;
                    elseif ($battle->opponent_points > $battle->initiator_points) $battle->winner_id = $battle->opponent_id;
                    else $battle->winner_id = null; // tie
                    $battle->status = 'completed';
                    $battle->completed_at = now();
                } else {
                    $battle->status = 'in_progress';
                }
            } else {
                // If deferring, set in_progress so UI knows submission was recorded
                $battle->status = 'in_progress';
            }

            $battle->save();
        });

        // After transaction, check achievements if completed
        $awarded = [];
        if ($battle->status === 'completed') {
            // Check achievements for winner
            if ($battle->winner_id) {
                $this->achievementService->checkAchievements($battle->winner_id, [
                    'type' => 'battle_won',
                    'score' => $battle->winner_id === $battle->initiator_id ? $battle->initiator_points : $battle->opponent_points,
                    'total' => $total
                ]);
            }
            
            // Check achievements for both participants
            foreach ([$battle->initiator_id, $battle->opponent_id] as $userId) {
                $userPoints = $userId === $battle->initiator_id ? $battle->initiator_points : $battle->opponent_points;
                $aw = $this->achievementService->checkAchievements($userId, [
                    'type' => 'battle_completed', 
                    'score' => $userPoints,
                    'total' => $total
                ]);
                if (is_array($aw) && count($aw)) $awarded = array_merge($awarded, $aw);
            }
        }

        $scorePercent = $total ? round($correct / $total * 100, 1) : 0;

        // Include any awarded achievements and refreshed user (requesting user)
        $refreshedUser = $user->fresh()->load('achievements');
        return response()->json(['ok' => true, 'result' => ['score' => $scorePercent, 'correct' => $correct, 'total' => $total, 'questions' => $detailed], 'battle' => $battle, 'deferred' => $defer, 'awarded_achievements' => $awarded, 'user' => $refreshedUser]);
    }

    /**
     * Mark a deferred battle for the requesting user and return the result. Requires active subscription.
     */
    public function mark(Request $request, Battle $battle)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // Subscription or one-off purchase check
        $activeSub = \App\Models\Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();

        $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'battle')
            ->where('item_id', $battle->id)
            ->where('status', 'confirmed')
            ->exists();

        if (!$activeSub && !$hasOneOff) {
            return response()->json(['ok' => false, 'message' => 'Subscription or one-off purchase required'], 403);
        }

        // Enforce package limits for battle results/marks
        if ($activeSub && $activeSub->package && is_array($activeSub->package->features)) {
            $features = $activeSub->package->features;
            $limit = $features['limits']['battle_results'] ?? $features['limits']['results'] ?? null;
            if ($limit !== null) {
                $today = now()->startOfDay();
                $used = Battle::where(function($q) use ($user) {
                        $q->where('initiator_id', $user->id)->orWhere('opponent_id', $user->id);
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
                        'message' => 'Daily battle result reveal limit reached for your plan'
                    ], 403);
                }
            }
        }

        // Only allow participants
        if (!in_array($user->id, [$battle->initiator_id, $battle->opponent_id])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Recompute correct_flag for any submissions with null correct_flag
        $questionMap = $battle->questions()->get()->keyBy('id');
        $totalMarked = 0;

        DB::transaction(function () use ($battle, $questionMap, &$totalMarked) {
            $subs = $battle->submissions()->whereNull('correct_flag')->get();
            foreach ($subs as $s) {
                $q = $questionMap->get($s->question_id) ?? \App\Models\Question::find($s->question_id);
                if (!$q) continue;
                $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
                $selected = $s->selected;
                $isCorrect = false;
                if (is_array($selected)) {
                    $isCorrect = array_values($selected) == array_values($correctAnswers);
                } else {
                    $isCorrect = in_array($selected, $correctAnswers);
                }
                $s->correct_flag = $isCorrect;
                $s->save();
                $totalMarked++;
            }

            // Recompute aggregated points
            $initiatorCorrect = $battle->submissions()->where('user_id', $battle->initiator_id)->where('correct_flag', true)->count();
            $opponentCorrect = $battle->submissions()->where('user_id', $battle->opponent_id)->where('correct_flag', true)->count();

            $battle->initiator_points = $initiatorCorrect;
            $battle->opponent_points = $opponentCorrect;

            if (!is_null($battle->initiator_points) && !is_null($battle->opponent_points)) {
                if ($battle->initiator_points > $battle->opponent_points) $battle->winner_id = $battle->initiator_id;
                elseif ($battle->opponent_points > $battle->initiator_points) $battle->winner_id = $battle->opponent_id;
                else $battle->winner_id = null; // tie
                $battle->status = 'completed';
                $battle->completed_at = now();
            }

            $battle->save();
        });

        // After marking, award points to participants and check achievements
        // Points: each correct answer = 10 points (similar to quizzes)
        $initiatorPoints = $battle->initiator_points ?? 0;
        $opponentPoints = $battle->opponent_points ?? 0;

        try {
            DB::beginTransaction();
            // increment user points where model supports it
            $initUser = \App\Models\User::find($battle->initiator_id);
            $oppUser = \App\Models\User::find($battle->opponent_id);
            if ($initUser && method_exists($initUser, 'increment')) {
                try { $initUser->increment('points', $initiatorPoints * 1); } catch (\Throwable $_) {}
            }
            if ($oppUser && method_exists($oppUser, 'increment')) {
                try { $oppUser->increment('points', $opponentPoints * 1); } catch (\Throwable $_) {}
            }

            // Achievements for both participants
            $awarded = [];
            foreach ([$battle->initiator_id, $battle->opponent_id] as $userId) {
                $userPoints = $userId === $battle->initiator_id ? $battle->initiator_points : $battle->opponent_points;
                $aw = $this->achievementService->checkAchievements($userId, [
                    'type' => 'battle_completed',
                    'score' => $userPoints,
                    'total' => $totalMarked
                ]);
                if (is_array($aw) && count($aw)) $awarded = array_merge($awarded, $aw);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            try { Log::warning('Failed to award points after battle mark: '.$e->getMessage()); } catch (\Throwable $_) {}
        }

        // Reuse result to return latest view and include awarded achievements and user
        $res = $this->result($request, $battle);
        // $this->result returns a JsonResponse; append awarded_achievements and user if possible
        try {
            $refreshedUser = $request->user()->fresh()->load('achievements');
            $original = $res->getData(true);
            $original['awarded_achievements'] = $awarded ?? [];
            $original['user'] = $refreshedUser;
            return response()->json($original);
        } catch (\Throwable $_) {
            return $res;
        }
    }

    /**
     * Allow the initiator to complete a pending battle solo if no other participant joined
     * Expected payload: { answers: [...] }
     */
    public function soloComplete(Request $request, Battle $battle)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        // Only initiator can perform solo complete
        if ($battle->initiator_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Only allowed if opponent is null or equal to initiator (no-one joined) and battle is waiting
        if (($battle->opponent_id && $battle->opponent_id !== $battle->initiator_id) || $battle->status !== 'waiting') {
            return response()->json(['message' => 'Battle already has an opponent or is not in waiting state'], 400);
        }

        $payload = $request->validate(['answers' => 'required|array']);
        $answers = $payload['answers'] ?? [];

        // Score as the submit method but then set opponent_points = 0 and complete
        $questionMap = $battle->questions()->get()->keyBy('id');
        $detailed = [];
        $total = count($answers);
        $correct = 0;

        DB::transaction(function () use ($answers, $user, $battle, $questionMap, &$detailed, &$correct) {
            foreach ($answers as $a) {
                $qid = intval($a['question_id'] ?? 0);
                $selected = $a['selected'] ?? null;
                $timeTaken = $a['time_taken'] ?? null;
                $q = $questionMap->get($qid) ?? \App\Models\Question::find($qid);
                if (!$q) continue;

                $isCorrect = false;
                $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
                if (is_array($selected)) {
                    $isCorrect = array_values($selected) == array_values($correctAnswers);
                } else {
                    $isCorrect = in_array($selected, $correctAnswers);
                }

                BattleSubmission::updateOrCreate([
                    'battle_id' => $battle->id,
                    'user_id' => $user->id,
                    'question_id' => $qid,
                ], [
                    'selected' => $selected,
                    'time_taken' => $timeTaken,
                    'correct_flag' => $isCorrect,
                ]);

                if ($isCorrect) $correct++;
                $detailed[] = [
                    'question_id' => $qid,
                    'body' => $q->body,
                    'selected' => $selected,
                    'correct' => $correctAnswers,
                    'time_taken' => $timeTaken,
                    'correct_flag' => $isCorrect,
                ];
            }

            // Set initiator points from persisted submissions
            $initiatorCorrect = $battle->submissions()->where('user_id', $battle->initiator_id)->where('correct_flag', true)->count();
            $battle->initiator_points = $initiatorCorrect;
            // set opponent points to 0 for solo completion
            $battle->opponent_points = 0;

            // determine winner
            if ($battle->initiator_points > $battle->opponent_points) $battle->winner_id = $battle->initiator_id;
            elseif ($battle->opponent_points > $battle->initiator_points) $battle->winner_id = $battle->opponent_id;
            else $battle->winner_id = null;

            $battle->status = 'completed';
            $battle->completed_at = now();

            $battle->save();
        });

        // achievements
        $awarded = [];
        if ($battle->winner_id) {
            $aw = $this->achievementService->checkAchievements($battle->winner_id, [
                'type' => 'battle_won',
                'score' => $battle->winner_id === $battle->initiator_id ? $battle->initiator_points : $battle->opponent_points,
                'total' => $total
            ]);
            if (is_array($aw) && count($aw)) $awarded = array_merge($awarded, $aw);
        }

        $refreshedUser = $request->user()->fresh()->load('achievements');

        return response()->json(['ok' => true, 'result' => ['score' => $total ? round($correct / $total * 100, 1) : 0, 'correct' => $correct, 'total' => $total, 'questions' => $detailed], 'battle' => $battle, 'awarded_achievements' => $awarded, 'user' => $refreshedUser]);
    }

    /**
     * Return the computed results for a battle in the shape the frontend expects.
     */
    public function result(Request $request, Battle $battle)
    {
        $user = $request->user();
        // Check subscription
        $activeSub = \App\Models\Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();
        if (!$activeSub) {
            return response()->json(['ok' => false, 'message' => 'Subscription required'], 403);
        }

        // Enforce package limits for returning battle results
        if ($activeSub && $activeSub->package && is_array($activeSub->package->features)) {
            $features = $activeSub->package->features;
            $limit = $features['limits']['battle_results'] ?? $features['limits']['results'] ?? null;
            if ($limit !== null) {
                $today = now()->startOfDay();
                $used = Battle::where(function($q) use ($user) {
                        $q->where('initiator_id', $user->id)->orWhere('opponent_id', $user->id);
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
                        'message' => 'Daily battle result reveal limit reached for your plan'
                    ], 403);
                }
            }
        }

        $battle->load('questions');
        // If battle not completed yet, still return present points if any
        $initiatorPoints = $battle->initiator_points ?? 0;
        $opponentPoints = $battle->opponent_points ?? 0;

        // Load persisted submissions if table exists
        $questions = [];
        $submissionsByQuestion = [];
        if (Schema::hasTable('battle_submissions')) {
            $subs = $battle->submissions()->get()->groupBy('question_id');
            foreach ($subs as $qid => $group) {
                $submissionsByQuestion[$qid] = $group->keyBy('user_id');
            }
        }

        foreach ($battle->questions as $q) {
            $qid = $q->id;
            $initiatorSub = $submissionsByQuestion[$qid][$battle->initiator_id] ?? null;
            $opponentSub = $submissionsByQuestion[$qid][$battle->opponent_id] ?? null;

            $questions[] = [
                'question_id' => $qid,
                'body' => $q->body,
                'initiator' => $initiatorSub ? [
                    'selected' => $initiatorSub->selected,
                    'time_taken' => $initiatorSub->time_taken,
                    'correct_flag' => (bool) $initiatorSub->correct_flag,
                ] : null,
                'opponent' => $opponentSub ? [
                    'selected' => $opponentSub->selected,
                    'time_taken' => $opponentSub->time_taken,
                    'correct_flag' => (bool) $opponentSub->correct_flag,
                ] : null,
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
