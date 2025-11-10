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
use PhpOffice\PhpSpreadsheet\IOFactory;

class AdminTournamentController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'can:viewFilament']);
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
     * Attach questions to a specific TournamentBattle using filters.
     * Mirrors the BattleController select/attach behavior but for TournamentBattle.
     * Admin-only (route is protected by can:viewFilament middleware).
     */
    /**
     * Attach questions to a specific TournamentBattle using filters or CSV upload.
     * Accepts either a Request (from HTTP route) or an array (when called from Filament action).
     * CSV must include a header with `id` or `question_id` column to attach existing bank questions.
     */
    public function attachQuestionsToBattle($request, Tournament $tournament, TournamentBattle $battle)
    {
        // Ensure the battle belongs to the tournament
        if ($battle->tournament_id !== $tournament->id) {
            return response()->json(['message' => 'Battle does not belong to tournament'], 400);
        }

        // Normalize payload whether $request is Request or array
        $payload = [];
        $uploadedFile = null;
        if ($request instanceof Request) {
            $payload = $request->all();
            $uploadedFile = $request->file('csv') ?? $request->file('file') ?? null;
        } elseif (is_array($request)) {
            $payload = $request;
            // Filament action may provide the uploaded file object in the payload
            $uploadedFile = $payload['csv'] ?? $payload['file'] ?? null;
        }

        // If CSV/file provided, parse and either attach existing question ids or create new questions from rows
        if ($uploadedFile) {
            try {
                if (is_string($uploadedFile) && file_exists($uploadedFile)) {
                    $path = $uploadedFile;
                } elseif (method_exists($uploadedFile, 'getRealPath')) {
                    $path = $uploadedFile->getRealPath();
                } else {
                    return response()->json(['message' => 'Invalid uploaded file'], 422);
                }

                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                // helper to normalize tabular file into headers + rows (rows as plain arrays)
                $parseTabular = function (string $path, string $ext) {
                    $headers = [];
                    $rows = [];
                    if (in_array($ext, ['xls', 'xlsx', 'xlsm'])) {
                        // Use PhpSpreadsheet to read Excel files
                        $spreadsheet = IOFactory::load($path);
                        $sheet = $spreadsheet->getActiveSheet();
                        $array = $sheet->toArray(null, true, true, true);
                        if (empty($array)) return [[], []];
                        // convert first row to headers
                        $first = array_shift($array);
                        $headers = array_map(function ($h) { return strtolower(trim((string)$h)); }, array_values($first));
                        foreach ($array as $r) {
                            $rows[] = array_values($r);
                        }
                    } else {
                        // CSV/TXT
                        $FH = fopen($path, 'r');
                        if (!$FH) return [[], []];
                        $rawHeaders = fgetcsv($FH);
                        if ($rawHeaders === false) { fclose($FH); return [[], []]; }
                        $headers = array_map(function ($h) { return strtolower(trim((string)$h)); }, $rawHeaders);
                        while (($row = fgetcsv($FH)) !== false) {
                            $rows[] = $row;
                        }
                        fclose($FH);
                    }
                    return [$headers, $rows];
                };

                [$headers, $rows] = $parseTabular($path, $ext);
                if (empty($headers)) {
                    return response()->json(['message' => 'Empty or unreadable file'], 422);
                }

                // If there's an id column, treat this as "attach existing questions by id"
                $idKeyIndexes = [];
                foreach ($headers as $i => $h) {
                    if (in_array($h, ['id', 'question_id', 'questionid', 'question id'])) { $idKeyIndexes[] = $i; }
                }

                DB::beginTransaction();
                $attachData = [];

                if (!empty($idKeyIndexes)) {
                    // prefer first id column
                    $idIdx = $idKeyIndexes[0];
                    $ids = [];
                    foreach ($rows as $row) {
                        if (!isset($row[$idIdx])) continue;
                        $val = trim((string)$row[$idIdx]);
                        if ($val === '') continue;
                        if (is_numeric($val)) $ids[] = intval($val);
                    }
                    if (empty($ids)) {
                        DB::rollBack();
                        return response()->json(['message' => 'No question ids found in file'], 422);
                    }
                    $questions = Question::whereIn('id', $ids)->get()->keyBy('id');
                    foreach ($ids as $i => $qid) {
                        if ($questions->has($qid)) $attachData[$qid] = ['position' => $i];
                    }
                    if (!empty($attachData)) {
                        $battle->questions()->detach();
                        $battle->questions()->attach($attachData);
                    }
                    DB::commit();
                    return response()->json(['attached' => count($attachData), 'questions' => $battle->questions()->get()]);
                }

                // Otherwise: assume rows contain question definitions; attempt to create them then attach
                // map header names to canonical keys
                $canonical = array_map(function ($h) { return preg_replace('/[^a-z0-9_]/', '_', $h); }, $headers);

                $created = [];
                $userId = null;
                if ($request instanceof Request && $request->user()) $userId = $request->user()->id;
                foreach ($rows as $rIdx => $row) {
                    $rowData = [];
                    foreach ($canonical as $i => $key) {
                        $rowData[$key] = isset($row[$i]) ? trim((string)$row[$i]) : null;
                    }

                    // Minimal mapping rules: body/prompt -> body, type -> type
                    // support multiple common column names for question text
                    $body = $rowData['body'] ?? $rowData['prompt'] ?? $rowData['question'] ?? $rowData['text'] ?? null;
                    if (!$body) continue; // skip invalid rows
                    $type = $rowData['type'] ?? $rowData['question_type'] ?? 'mcq';

                    // Build options array: check for 'options' JSON, 'choices' pipe-delimited, or option_1..option_10
                    $options = [];
                    if (!empty($rowData['options'])) {
                        $maybe = $rowData['options'];
                        $decoded = json_decode($maybe, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            // normalize to array of ['text' => ...]
                            foreach ($decoded as $opt) {
                                if (is_array($opt) && isset($opt['text'])) $options[] = ['text' => $opt['text']];
                                elseif (is_string($opt)) $options[] = ['text' => $opt];
                            }
                        } else {
                            // try pipe-splitting
                            $parts = array_filter(array_map('trim', explode('|', $maybe)));
                            foreach ($parts as $p) $options[] = ['text' => $p];
                        }
                    } elseif (!empty($rowData['choices'])) {
                        $parts = array_filter(array_map('trim', preg_split('/[|,]/', $rowData['choices'])));
                        foreach ($parts as $p) $options[] = ['text' => $p];
                    } else {
                        // option_1..option_10 or option1..option10 (both common patterns)
                        for ($i = 1; $i <= 10; $i++) {
                            $k1 = 'option_' . $i;
                            $k2 = 'option' . $i;
                            if (!empty($rowData[$k1])) {
                                $options[] = ['text' => $rowData[$k1]];
                            } elseif (!empty($rowData[$k2])) {
                                $options[] = ['text' => $rowData[$k2]];
                            }
                        }
                    }

                    // correct/answer mapping: could be index or text
                    $correct = null;
                    $corrects = null;
                    $rawCorrect = $rowData['correct'] ?? $rowData['correct_answer'] ?? $rowData['answers'] ?? null;
                    if ($rawCorrect !== null) {
                        // supports multiple with | or , delimiter
                        if (strpos($rawCorrect, '|') !== false || strpos($rawCorrect, ',') !== false) {
                            $parts = array_map('trim', preg_split('/[|,]/', $rawCorrect));
                            $mapped = [];
                            foreach ($parts as $p) {
                                if (is_numeric($p)) $mapped[] = intval($p);
                                else {
                                    $idx = null;
                                    foreach ($options as $oi => $opt) { if (strcasecmp($opt['text'], $p) === 0) { $idx = $oi; break; } }
                                    if ($idx !== null) $mapped[] = $idx;
                                }
                            }
                            $corrects = array_values(array_unique(array_filter($mapped, 'is_int')));
                        } else {
                            $p = trim((string)$rawCorrect);
                            if (is_numeric($p)) $correct = intval($p);
                            else {
                                foreach ($options as $oi => $opt) { if (strcasecmp($opt['text'], $p) === 0) { $correct = $oi; break; } }
                            }
                        }
                    }

                    $createData = [
                        'body' => $body,
                        'type' => $type,
                        'options' => $options ?: null,
                        'marks' => isset($rowData['marks']) && is_numeric($rowData['marks']) ? floatval($rowData['marks']) : null,
                        'difficulty' => $rowData['difficulty'] ?? null,
                        'explanation' => $rowData['explanation'] ?? null,
                        'youtube_url' => $rowData['youtube_url'] ?? $rowData['youtube'] ?? null,
                        'subject_id' => isset($rowData['subject_id']) && is_numeric($rowData['subject_id']) ? intval($rowData['subject_id']) : (isset($rowData['subject']) && is_numeric($rowData['subject']) ? intval($rowData['subject']) : null),
                        'topic_id' => isset($rowData['topic_id']) && is_numeric($rowData['topic_id']) ? intval($rowData['topic_id']) : (isset($rowData['topic']) && is_numeric($rowData['topic']) ? intval($rowData['topic']) : null),
                        'grade_id' => isset($rowData['grade_id']) && is_numeric($rowData['grade_id']) ? intval($rowData['grade_id']) : null,
                        'level_id' => isset($rowData['level_id']) && is_numeric($rowData['level_id']) ? intval($rowData['level_id']) : null,
                        'is_banked' => true,
                        'is_approved' => true,
                    ];
                    if ($corrects !== null) $createData['corrects'] = $corrects;
                    if ($correct !== null) $createData['correct'] = $correct;
                    if ($userId) $createData['created_by'] = $userId;

                    $question = Question::create($createData);
                    $created[] = $question;
                }

                // attach created questions preserving order
                foreach ($created as $i => $q) {
                    $attachData[$q->id] = ['position' => $i];
                }
                if (!empty($attachData)) {
                    $battle->questions()->detach();
                    $battle->questions()->attach($attachData);
                }

                DB::commit();
                return response()->json(['created' => count($created), 'attached' => count($attachData), 'questions' => $battle->questions()->get()]);
            } catch (\Throwable $e) {
                DB::rollBack();
                return response()->json(['message' => 'Failed to process file: ' . $e->getMessage()], 500);
            }
        }

        // Fallback: select from bank using filters (existing behavior)
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