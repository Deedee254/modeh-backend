<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Question;
use App\Models\TournamentBattle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use App\Services\TournamentQuestionService;
use Illuminate\Http\UploadedFile;

class AdminTournamentController extends Controller
{
    protected $tournamentQuestionService;

    public function __construct(TournamentQuestionService $tournamentQuestionService)
    {
        $this->middleware(['auth:sanctum', 'can:viewFilament']);
        $this->tournamentQuestionService = $tournamentQuestionService;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'prize_pool' => 'nullable|numeric|min:0',
            'max_participants' => 'nullable|integer|min:2|max:8',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'subject_id' => 'required|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'required|exists:grades,id'
        ]);

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'upcoming';

        $tournament = Tournament::create($data);

        return response()->json($tournament);
    }

    public function update(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'prize_pool' => 'nullable|numeric|min:0',
            'max_participants' => 'nullable|integer|min:2|max:8',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'status' => 'sometimes|in:upcoming,active,completed',
            'subject_id' => 'sometimes|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'sometimes|exists:grades,id'
        ]);

        $tournament->update($data);

        return response()->json($tournament);
    }

    public function attachQuestions(Request $request, Tournament $tournament)
    {
        $request->validate([
            'questions' => 'required|array',
            'questions.*' => 'exists:questions,id'
        ]);

        $attachData = [];
        foreach ($request->questions as $i => $questionId) {
            $attachData[$questionId] = ['position' => $i];
        }

        $tournament->questions()->sync($attachData);

        return response()->json([
            'message' => 'Questions attached successfully',
            'questions' => $tournament->questions()->get()
        ]);
    }

    public function generateMatches(Request $request, Tournament $tournament) 
    {
        // allow admin to pass explicit participant IDs to generate a specific round
        $participantIds = $request->input('participant_ids');
        $round = intval($request->input('round', 1));
        $scheduledAt = $request->input('scheduled_at') ? \Illuminate\Support\Carbon::parse($request->input('scheduled_at')) : $tournament->start_date;

        // If no explicit participant ids provided, use registered participants
        if (!is_array($participantIds) || empty($participantIds)) {
            // Only generate if tournament is upcoming
            if ($tournament->status !== 'upcoming') {
                return response()->json(['message' => 'Can only generate matches for upcoming tournaments'], 400);
            }

            $participants = $tournament->participants()->get()->pluck('id')->toArray();
            $participantIds = $participants;
        }

        if (count($participantIds) < 2) {
            // If only one participant remains, finalize
            if (count($participantIds) === 1) {
                $tournament->finalizeWithWinner((int) $participantIds[0]);
                return response()->json(['message' => 'Tournament completed with single participant']);
            }
            return response()->json(['message' => 'Need at least 2 participants'], 400);
        }

        // If this is the first round for an upcoming tournament, randomize entry order
        if ($round === 1 && $tournament->status === 'upcoming') {
            shuffle($participantIds);
        }

        // create battles using the Tournament helper
        $created = $tournament->createBattlesForRound($participantIds, $round, $scheduledAt);

        // Activate tournament if this is the first round
        if ($round === 1 && $tournament->status === 'upcoming') {
            $tournament->status = 'active';
            $tournament->save();
        }

        return response()->json([
            'message' => 'Tournament battles generated successfully',
            'created' => count($created),
            'battles' => $tournament->battles()->where('round', $round)->with(['player1', 'player2'])->get()
        ]);
    }

    public function destroy(Tournament $tournament)
    {
        $tournament->delete();
        return response()->json(['message' => 'Tournament deleted successfully']);
    }

    /**
     * Attach questions to a specific TournamentBattle using filters or CSV upload.
     */
    public function attachQuestionsToBattle(Request $request, Tournament $tournament, TournamentBattle $battle)
    {
        if ($battle->tournament_id !== $tournament->id) {
            return response()->json(['message' => 'Battle does not belong to tournament'], 400);
        }

        $uploadedFile = $request->file('csv') ?? $request->file('file');

        if ($uploadedFile) {
            try {
                $result = $this->tournamentQuestionService->attachQuestionsFromCsv($tournament, $battle, $uploadedFile);
                return response()->json($result);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Failed to process file: ' . $e->getMessage()], 500);
            }
        }

        // Fallback: select from bank using filters (existing behavior)
        $payload = $request->all();
        $settings = $payload['settings'] ?? [];
        $data = array_merge($settings, $payload);

        $hasFilter = isset($data['grade_id']) || isset($data['subject_id']) || isset($data['topic_id']) || isset($data['difficulty']) || isset($data['topic']) || isset($data['grade']) || isset($data['level_id']);
        if (!$hasFilter && !isset($payload['questions']) ) {
            return response()->json(['message' => 'Provide question IDs (questions[]) or at least one filter (grade_id, subject_id, topic_id or difficulty) to attach questions'], 422);
        }

        // If explicit question ids provided in payload, attach them
        if (isset($payload['questions']) && is_array($payload['questions']) && !empty($payload['questions'])) {
            $ids = array_values(array_filter(array_map('intval', $payload['questions'])));
            $questions = Question::whereIn('id', $ids)->get();
            $attachData = [];
            foreach ($ids as $i => $qid) {
                if ($questions->firstWhere('id', $qid)) $attachData[$qid] = ['position' => $i];
            }
            if (!empty($attachData)) {
                $battle->questions()->detach();
                $battle->questions()->attach($attachData);
            }
            return response()->json(['attached' => count($attachData), 'questions' => $battle->questions()->get()]);
        }

        // Otherwise: select via filters
        $perPage = intval($payload['question_count'] ?? $payload['number_of_questions'] ?? $settings['question_count'] ?? $settings['number_of_questions'] ?? $payload['per_page'] ?? $settings['per_page'] ?? 10);
        if ($perPage < 1) $perPage = 10;

        $topic = $payload['topic_id'] ?? $settings['topic_id'] ?? $payload['topic'] ?? $settings['topic'] ?? null;
        $difficulty = $payload['difficulty'] ?? $settings['difficulty'] ?? null;
        $grade = $payload['grade_id'] ?? $settings['grade_id'] ?? $payload['grade'] ?? $settings['grade'] ?? null;
        $level = $payload['level_id'] ?? $settings['level_id'] ?? null;
        $subject = $payload['subject_id'] ?? $settings['subject_id'] ?? null;
        $random = $payload['random'] ?? $settings['random'] ?? 1;

        $q = Question::query();
        try {
            if ($topic && Schema::hasColumn('questions', 'topic_id')) $q->where('topic_id', $topic);
            if ($subject && Schema::hasColumn('questions', 'subject_id')) $q->where('subject_id', $subject);
            if ($difficulty && Schema::hasColumn('questions', 'difficulty')) $q->where('difficulty', $difficulty);
            if ($grade && Schema::hasColumn('questions', 'grade_id')) $q->where('grade_id', $grade);

            if ($level) {
                if (Schema::hasTable('grades') && Schema::hasColumn('grades', 'level_id') && Schema::hasColumn('questions', 'grade_id')) {
                    $gradeIds = \App\Models\Grade::where('level_id', $level)->pluck('id')->toArray();
                    if (!empty($gradeIds)) {
                        $q->whereIn('grade_id', $gradeIds);
                    } else {
                        $q->whereRaw('0 = 1');
                    }
                }
            }
        } catch (\Throwable $_) {
            // ignore filter failures
        }

        if ($random) {
            $questions = $q->inRandomOrder()->limit($perPage)->get();
        } else {
            $questions = $q->limit($perPage)->get();
        }

        if ($questions->isEmpty()) {
            $questions = Question::inRandomOrder()->limit($perPage)->get();
        }

        // attach with position (replace existing)
        $battle->questions()->detach();
        $attachData = [];
        foreach ($questions as $i => $question) {
            $attachData[$question->id] = ['position' => $i];
        }
        if (!empty($attachData)) {
            $battle->questions()->attach($attachData);
        }

        return response()->json(['attached' => count($questions), 'questions' => $battle->questions()->get()]);
    }
}