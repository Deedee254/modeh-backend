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
        $data['status'] = 'pending';
        if ($request->has('settings')) {
            $data['settings'] = $request->input('settings');
        }

        $battle = Battle::create($data);

        // If frontend requested atomic attach, perform selection now
        if ($request->boolean('attach_questions')) {
            $this->selectAndAttachQuestions($battle, $request->all());
            $battle->load('questions');
        }

    // return created battle including uuid for sharing
    return response()->json($battle);
    }

    public function index(Request $request)
    {
        $battles = Battle::with('questions')->latest()->limit(50)->get();
        return response()->json(['battles' => $battles]);
    }

    public function show(Request $request, Battle $battle)
    {
        $battle->load('questions');
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

        // Only allow joining if battle is pending or has no opponent
        if ($battle->opponent_id && $battle->opponent_id !== $battle->initiator_id) {
            return response()->json(['message' => 'Battle already has an opponent'], 400);
        }

        $battle->opponent_id = $user->id;
        $battle->status = 'pending';
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
        $perPage = intval($params['per_page'] ?? ($params['settings']['per_page'] ?? 10));
        $topic = $params['topic'] ?? ($params['settings']['topic'] ?? null);
        $difficulty = $params['difficulty'] ?? ($params['settings']['difficulty'] ?? null);
        $grade = $params['grade'] ?? ($params['settings']['grade'] ?? null);
        $random = $params['random'] ?? ($params['settings']['random'] ?? 1);

        $q = Question::query();
        // We no longer use `for_battle` filter: the bank determines eligibility.
        if ($topic && Schema::hasColumn('questions', 'topic_id')) $q->where('topic_id', $topic);
        if ($difficulty && Schema::hasColumn('questions', 'difficulty')) $q->where('difficulty', $difficulty);
        if ($grade && Schema::hasColumn('questions', 'grade_id')) $q->where('grade_id', $grade);

        if ($random) {
            $questions = $q->inRandomOrder()->limit($perPage)->get();
        } else {
            $questions = $q->limit($perPage)->get();
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

                // Persist or update the per-question submission
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

            // Recompute aggregated points from persisted submissions for both participants
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

            $battle->save();
        });

        // After transaction, check achievements if completed
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
                $this->achievementService->checkAchievements($userId, [
                    'type' => 'battle_completed', 
                    'score' => $userPoints,
                    'total' => $total
                ]);
            }
        }

        $scorePercent = $total ? round($correct / $total * 100, 1) : 0;

        return response()->json(['ok' => true, 'result' => ['score' => $scorePercent, 'correct' => $correct, 'total' => $total, 'questions' => $detailed], 'battle' => $battle]);
    }

    /**
     * Return the computed results for a battle in the shape the frontend expects.
     */
    public function result(Request $request, Battle $battle)
    {
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
