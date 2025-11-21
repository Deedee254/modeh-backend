<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
use App\Services\MediaMetadataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class QuestionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function index(Request $request)
    {
        // Return questions. By default return questions created by user unless
        // the request explicitly asks for banked/random questions (used by
        // battles/daily-challenge). When `for_battle=1` or `random=1` or
        // `banked=1` is present we return banked/global questions and ignore
        // the created_by restriction so non-admin quizees can fetch the
        // public question bank.
        $user = $request->user();
    $query = Question::query()->with(['grade.level', 'subject', 'topic', 'quiz']);

    $isBankQuery = $request->boolean('random') || $request->boolean('banked');
        if (!$isBankQuery) {
            if (!isset($user->is_admin) || !$user->is_admin) {
                $query->where('created_by', $user->id);
            }
        }

        // Honor explicit banked param when hitting /api/questions
        if ($request->has('banked') && Schema::hasColumn('questions', 'is_banked')) {
            $query->where('is_banked', $request->boolean('banked') ? 1 : 0);
        }
        // Basic search by text/type
        if ($q = $request->get('q')) {
            $query->where(function($qq) use ($q) {
                $qq->where('body', 'like', "%{$q}%")
                   ->orWhere('type', 'like', "%{$q}%");
            });
        }
        // randomize when requested
        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderByDesc('id');
        }

    // Optional server-side limit to avoid accidental huge payloads. Frontend will paginate client-side.
    $limit = (int) ($request->get('limit') ?? 0);
    if ($limit > 0) {
        // enforce a reasonable cap
        $limit = min($limit, 1000);
        $query->limit($limit);
    }

    // Return all matching questions (frontend will handle pagination)
    $results = $query->get();
    return response()->json(['questions' => $results]);
    }

    /**
     * Public question bank endpoint: returns global banked/random questions
     * with optional filters. This keeps quiz-master listing (`index`) separate.
     */
    public function bank(Request $request)
    {
        // Build a base query with filters applied so we can both count and
        // fetch a paginated subset when the frontend requests pages.
        $baseQuery = Question::query();
        // The public question bank is independent of any quiz-master-set `for_battle` flag.
        // We intentionally do not filter by `for_battle` here.
        // Accept either explicit *_id keys or shorthand keys used by frontend (grade, subject, topic)
    $grade = $request->get('grade_id') ?? $request->get('grade');
    $subject = $request->get('subject_id') ?? $request->get('subject');
    $topic = $request->get('topic_id') ?? $request->get('topic');
    $difficulty = $request->get('difficulty');
    if ($grade) $baseQuery->where('grade_id', $grade);
    if ($subject) $baseQuery->where('subject_id', $subject);
    if ($topic) $baseQuery->where('topic_id', $topic);
    if ($difficulty) $baseQuery->where('difficulty', $difficulty);

        // Support filtering by level (frontend may send `level` or `level_id`). If provided,
        // constrain questions to grades that belong to that level (if grades table has level_id).
        $level = $request->get('level_id') ?? $request->get('level');
        if ($level) {
            try {
                if (Schema::hasTable('grades') && Schema::hasColumn('grades', 'level_id') && Schema::hasColumn('questions', 'grade_id')) {
                    $gradeIds = \App\Models\Grade::where('level_id', $level)->pluck('id')->toArray();
                    if (!empty($gradeIds)) {
                        $baseQuery->whereIn('grade_id', $gradeIds);
                    } else {
                        // no grades found for level — ensure no results
                        $baseQuery->whereRaw('0 = 1');
                    }
                }
            } catch (\Throwable $_) {
                // ignore and continue without level filtering
            }
        }

        if ($q = $request->get('q')) {
            $baseQuery->where(function($qq) use ($q) {
                $qq->where('body', 'like', "%{$q}%")
                   ->orWhere('type', 'like', "%{$q}%");
            });
        }

        // When fetching the public bank, exclude the requesting user's own
        // banked questions to match the expectations of client code and tests
        // which assume the quiz-master won't receive their own questions in this
        // public listing.
        $user = $request->user();
        if ($user) {
            $baseQuery->where('created_by', '!=', $user->id);
        }

        // Support optional paging parameters for the public bank UI. If the
        // frontend asks for page/per_page we'll return a paginator-like
        // envelope; otherwise we still support a simple `limit` param.
        $page = max(1, (int) $request->get('page', 1));
        $perPage = (int) $request->get('per_page', 0);
        $limit = (int) ($request->get('limit') ?? 0);
        if ($perPage <= 0) {
            if ($limit > 0) {
                $perPage = min($limit, 1000);
            } else {
                $perPage = 10;
            }
        } else {
            $perPage = min($perPage, 1000);
        }

        // Count total matching before applying page/limit
        try {
            $total = (clone $baseQuery)->count();
        } catch (\Throwable $_) {
            $total = 0;
        }

        // Build final query for fetching results (with relations)
        $query = (clone $baseQuery)->with(['grade.level', 'subject', 'topic', 'quiz']);
        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderByDesc('id');
        }

        // Apply paging/offset
        $query->skip(($page - 1) * $perPage)->take($perPage);

        $results = $query->get();

        $lastPage = (int) max(1, ceil($total / max(1, $perPage)));

        return response()->json(['questions' => [
            'data' => $results,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
        ]]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'quiz_id' => 'nullable|exists:quizzes,id',
            'type' => 'required|string|in:' . implode(',', array_keys(Question::getAllowedTypes())),
            'body' => 'required|string',
            'explanation' => 'nullable|string',
            'options' => 'nullable|array',
            'answers' => 'nullable|array',
            'parts' => 'nullable|array',
            'fill_parts' => 'nullable|array',
            'marks' => 'nullable|numeric',
            'correct' => 'nullable',
            'corrects' => 'nullable|array',
            'tags' => 'nullable|array',
            'solution_steps' => 'nullable|array',
            'subject_id' => 'nullable|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'nullable|exists:grades,id',
            'for_battle' => 'nullable|boolean',
            'is_quiz-master_marked' => 'nullable|boolean',
            'difficulty' => 'nullable|integer|min:1|max:5',
            'media' => 'nullable|file|max:10240|mimes:jpeg,png,jpg,gif,mp3,wav,ogg,m4a,mp4,webm',
            'youtube_url' => 'nullable|string|regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/',
            'media_metadata' => 'nullable|array',
        ]);

        if ($v->fails()) {
            // Log validation failure for easier debugging in dev/staging
            try {
                \Log::error('Question store validation failed', [
                    'request' => $request->all(),
                    'errors' => $v->errors()->toArray(),
                ]);
            } catch (\Throwable $_) {
                // ignore logging errors
            }

            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = $request->user();

        $mediaPath = null;
        $mediaType = null;
        $mediaMetadata = [];

        // Handle media file upload
        if ($request->hasFile('media')) {
            $mediaFile = $request->file('media');
            $mediaPath = Storage::disk('public')->putFile('question_media', $mediaFile);
            $mediaPath = Storage::url($mediaPath);
            
            // Determine media type
            $mimeType = $mediaFile->getMimeType();
            if (strpos($mimeType, 'image/') === 0) {
                $mediaType = 'image';
                $mediaMetadata = MediaMetadataService::extractImageMetadata($mediaFile);
            } elseif (strpos($mimeType, 'audio/') === 0) {
                $mediaType = 'audio';
                $mediaMetadata = MediaMetadataService::extractAudioMetadata($mediaFile);
            } elseif (strpos($mimeType, 'video/') === 0) {
                $mediaType = 'video';
                $mediaMetadata = MediaMetadataService::extractVideoMetadata($mediaFile);
            }
        }

        // Handle YouTube URLs
        $youtubeUrl = $request->get('youtube_url');
        if ($youtubeUrl) {
            $mediaType = 'youtube';
            // Extract video ID and other metadata
            if (preg_match('/(?:youtube\.com\/(?:[^\/\n\s]+\/\s*[^\/\n\s]+\/|(?:v|e(?:mbed)?)\/|\s*[^\/\n\s]+\?v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $youtubeUrl, $matches)) {
                $mediaMetadata['video_id'] = $matches[1];
            }
        }

        $payloadType = $request->get('type');
        $answers = $request->get('answers');
        $fillParts = $request->get('fill_parts');

        // Normalize answers based on question type
        if ($payloadType === 'fill_blank') {
            if (!is_array($answers)) {
                $answers = $answers ? [$answers] : [];
            }
            if (!is_array($fillParts)) {
                $fillParts = is_array($request->get('parts')) ? $request->get('parts') : [];
            }
            $fillParts = array_values(array_map(static function ($part) {
                return is_string($part) ? $part : (is_array($part) && isset($part['text']) ? (string) $part['text'] : '');
            }, $fillParts));
            $answers = array_values(array_map(static function ($ans) {
                return is_null($ans) ? '' : (string) $ans;
            }, $answers));
        } else {
            // For all other types, ensure answers is an array of the correct values
            if (is_array($answers)) {
                $answers = array_values(array_map(static fn($ans) => is_null($ans) ? null : (string) $ans, $answers));
            } elseif (!is_null($answers)) {
                $answers = [(string) $answers];
            } else {
                $answers = [];
            }
        }

        $parts = $request->get('parts');
        if ($payloadType === 'math') {
            if (!is_array($parts)) {
                $parts = [];
            }
            $parts = array_values(array_map(function ($part) {
                if (is_array($part)) {
                    return [
                        'text' => isset($part['text']) ? (string) $part['text'] : '',
                        'marks' => isset($part['marks']) && is_numeric($part['marks']) ? (float) $part['marks'] : 0,
                    ];
                }
                return [
                    'text' => is_string($part) ? $part : '',
                    'marks' => 0,
                ];
            }, $parts));
        } elseif (!is_array($parts)) {
            $parts = [];
        }

        $options = $request->get('options');
        if (is_array($options)) {
            $options = array_values(array_map(function ($opt, $idx) use ($answers, $payloadType) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                } else {
                    $text = is_string($opt) ? $opt : '';
                }

                // Set is_correct based on answers array for option-based question types
                $isCorrect = false;
                if (in_array($payloadType, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq', 'multi'], true) && is_array($answers)) {
                    $isCorrect = in_array((string)$idx, $answers, true);
                }

                return [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                ];
            }, $options, array_keys($options)));
        } else {
            $options = null;
        }

        $marks = $request->get('marks');
        if (!is_null($marks) && !is_numeric($marks)) {
            $marks = null;
        } elseif (!is_null($marks)) {
            $marks = (float) $marks;
        }

        $siteSettings = \App\Models\SiteSetting::current();
        $siteAutoQuestions = $siteSettings ? (bool)$siteSettings->auto_approve_questions : true;
        // If a quiz id is provided, try to infer missing taxonomy values from the quiz
        $quizObj = null;
        if ($request->quiz_id) {
            try { $quizObj = Quiz::find($request->quiz_id); } catch (\Throwable $_) { $quizObj = null; }
        }

        $question = Question::create([
            'quiz_id' => $request->quiz_id,
            'created_by' => $user->id,
            'type' => $request->type,
            'body' => $request->body,
            'explanation' => $request->get('explanation'),
            'options' => $options,
            'answers' => $answers,
            'parts' => $payloadType === 'fill_blank' ? $fillParts : $parts,
            'fill_parts' => $payloadType === 'fill_blank' ? $fillParts : null,
            'marks' => $marks,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'youtube_url' => $youtubeUrl,
            'media_metadata' => $mediaMetadata,
            'difficulty' => $request->get('difficulty', 3),
            'is_quiz-master_marked' => $request->get('is_quiz-master_marked', false),
            'is_approved' => $siteAutoQuestions,
            'tags' => $request->get('tags'),
            'solution_steps' => $request->get('solution_steps'),
            'subject_id' => $request->get('subject_id') ?? ($quizObj->subject_id ?? null),
            'topic_id' => $request->get('topic_id') ?? ($quizObj->topic_id ?? null),
            'grade_id' => $request->get('grade_id') ?? ($quizObj->grade_id ?? null),
            'level_id' => $request->get('level_id') ?? ($quizObj->level_id ?? null),
            'for_battle' => $request->get('for_battle', true),
        ]);

        // If attached to a quiz, trigger recalc of difficulty
        if ($question->quiz_id) {
            try {
                $quiz = Quiz::find($question->quiz_id);
                if ($quiz) $quiz->recalcDifficulty();
            } catch (\Exception $e) {
                // ignore
            }
        }

        // Load nested relations for client convenience
        try { $question->load(['grade.level', 'subject', 'topic', 'quiz']); } catch (\Throwable $_) {}

        return response()->json(['question' => $question], 201);
    }

    /**
     * Return a single question (owner or admin) for editing
     */
    public function show(Request $request, Question $question)
    {
        $user = $request->user();
        // allow owner or admin to fetch full question data
        if ($question->created_by && $question->created_by !== ($user->id ?? null) && !($user->is_admin ?? false)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Ensure nested relations are available to the client
        try { $question->load(['grade.level', 'subject', 'topic', 'quiz']); } catch (\Throwable $_) {}
        return response()->json(['question' => $question]);
    }

    /**
     * Create a question attached to a specific quiz (used by quiz-master UI)
     */
    public function storeForQuiz(Request $request, Quiz $quiz)
    {
        // merge quiz id into request then call store logic
        $request->merge(['quiz_id' => $quiz->id]);
        try {
            \Log::info('QuestionController@storeForQuiz incoming', [
                'quiz_id' => $quiz->id,
                'keys' => array_keys($request->all()),
            ]);
        } catch (\Throwable $_) {
            // ignore logging errors
        }

        // Delegate to the single-question store logic to avoid duplicating
        // validation and media handling. This returns the same JSON shape
        // as creating a question normally, but attached to the provided quiz.
        try {
            return $this->store($request);
        } catch (\Throwable $e) {
            try { \Log::error('QuestionController@storeForQuiz failed', ['quiz_id' => $quiz->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]); } catch (\Throwable $_) {}
            return response()->json(['message' => 'Failed to store question for quiz'], 500);
        }

    }

    /**
     * Check if user can bulk update questions for a quiz
     */
    private function canBulkUpdateQuiz(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        // minimal auth: only quiz owner or admin may bulk update
        return !($quiz->created_by && $quiz->created_by !== $user->id && !($user->is_admin ?? false));
    }

    /**
     * Validate and extract questions array from request
     */
    private function validateAndExtractQuestions(Request $request)
    {
        // Support multipart/form-data where 'questions' may be a JSON string
        if ($request->has('questions') && is_string($request->get('questions'))) {
            $decoded = json_decode($request->get('questions'), true);
            if (is_array($decoded)) {
                $request->merge(['questions' => $decoded]);
            }
        }

        // Validate 'questions' without throwing so we can log payload on failure
        $validator = Validator::make($request->all(), [
            'questions' => 'required|array'
        ]);

        if ($validator->fails()) {
            try {
                \Log::error('Bulk questions validation failed', [
                    'request' => $request->all(),
                    'errors' => $validator->errors()->toArray(),
                ]);
            } catch (\Throwable $_) {
                // ignore logging errors
            }

            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $request->get('questions');

        // Accept either JSON shape: { questions: [...] } OR directly an array: [ {...}, {...} ]
        // Earlier we decode JSON string payloads into an array above, but be defensive here.
        $items = [];
        if (is_array($payload)) {
            // numeric-indexed array (client sent [...])
            if (array_values($payload) === $payload) {
                $items = $payload;
            } elseif (isset($payload['questions']) && is_array($payload['questions'])) {
                // nested shape { questions: [...] }
                $items = $payload['questions'];
            }
        }

        // Fallback: ensure we still handle when request->get returned something unexpected
        if (empty($items) && $request->has('questions')) {
            $maybe = $request->get('questions');
            if (is_array($maybe) && array_values($maybe) === $maybe) $items = $maybe;
        }

        return $items;
    }

    /**
     * Process and save questions for bulk update
     */
    private function processBulkQuestions(array $items, array $mediaFiles, Quiz $quiz, $user)
    {
        $saved = [];
        $incomingIds = [];
        try {
            \Log::info('QuestionController@bulkUpdateForQuiz incoming', [
                'quiz_id' => $quiz->id,
                'incoming_count' => is_array($items) ? count($items) : null,
                'media_keys' => is_array($mediaFiles) ? array_keys($mediaFiles) : [],
            ]);
        } catch (\Throwable $_) {}
        foreach ($items as $idx => $q) {
            try {
                // Expect canonical 'type' key (mcq, multi, short, numeric, fill_blank, math, code).
                // Default to 'mcq' when not provided.
                $rawType = $q['type'] ?? 'mcq';
                $type = $rawType;

                $rawOptions = $q['options'] ?? null;
                $rawAnswers = $q['answers'] ?? null;
                $rawCorrect = $q['correct'] ?? null;
                $rawCorrects = $q['corrects'] ?? null;
                $rawParts = $q['parts'] ?? null;
                $rawFillParts = $q['fill_parts'] ?? null;

                if ($type === 'fill_blank') {
                    if (!is_array($rawAnswers)) {
                        $rawAnswers = $rawAnswers ? [$rawAnswers] : [];
                    }
                    $rawAnswers = array_values(array_map(static function ($ans) {
                        return is_null($ans) ? '' : (string) $ans;
                    }, $rawAnswers));
                    if (!is_array($rawFillParts)) {
                        $rawFillParts = is_array($rawParts) ? $rawParts : [];
                    }
                    $rawFillParts = array_values(array_map(static function ($part) {
                        return is_string($part) ? $part : (is_array($part) && isset($part['text']) ? (string) $part['text'] : '');
                    }, $rawFillParts));
                }

                if ($type === 'math') {
                    if (!is_array($rawParts)) {
                        $rawParts = [];
                    }
                    $rawParts = array_values(array_map(static function ($part) {
                        if (is_array($part)) {
                            return [
                                'text' => isset($part['text']) ? (string) $part['text'] : '',
                                'marks' => isset($part['marks']) && is_numeric($part['marks']) ? (float) $part['marks'] : 0,
                            ];
                        }
                        return [
                            'text' => is_string($part) ? $part : '',
                            'marks' => 0,
                        ];
                    }, $rawParts));
                } else {
                    if (!is_array($rawParts)) {
                        $rawParts = [];
                    }
                }

                if (is_array($rawOptions)) {
                    $rawOptions = array_values(array_map(function ($opt, $idx) use ($rawAnswers, $type) {
                        if (is_array($opt)) {
                            $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                        } else {
                            $text = is_string($opt) ? $opt : '';
                        }

                        // Set is_correct based on answers array for option-based question types
                        $isCorrect = false;
                        if (in_array($type, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq', 'multi'], true) && is_array($rawAnswers)) {
                            $isCorrect = in_array((string)$idx, $rawAnswers, true);
                        }

                        return [
                            'text' => $text,
                            'is_correct' => $isCorrect,
                        ];
                    }, $rawOptions, array_keys($rawOptions)));
                } else {
                    $rawOptions = null;
                }

                $marks = $q['marks'] ?? null;
                if (!is_null($marks) && !is_numeric($marks)) {
                    $marks = null;
                } elseif (!is_null($marks)) {
                    $marks = (float) $marks;
                }

                // normalize incoming question shape for Question::create/update
                $qData = [
                    'quiz_id' => $quiz->id,
                    'type' => $type,
                    'body' => $q['text'] ?? ($q['body'] ?? ''),
                    'explanation' => $q['explanation'] ?? null,
                    'options' => $rawOptions,
                    'answers' => $type === 'fill_blank' ? $rawAnswers : (is_array($rawAnswers) ? array_values(array_map(static fn($ans) => is_null($ans) ? null : (string) $ans, $rawAnswers)) : (!is_null($rawAnswers) ? [(string) $rawAnswers] : [])),
                    'parts' => $type === 'fill_blank' ? $rawFillParts : $rawParts,
                    'fill_parts' => $type === 'fill_blank' ? $rawFillParts : null,
                    'marks' => $marks,
                    'difficulty' => $q['difficulty'] ?? 3,
                    'tags' => $q['tags'] ?? null,
                    'solution_steps' => $q['solution_steps'] ?? null,
                    'subject_id' => $q['subject_id'] ?? $quiz->subject_id ?? null,
                    'topic_id' => $q['topic_id'] ?? $quiz->topic_id ?? null,
                    'grade_id' => $q['grade_id'] ?? $quiz->grade_id ?? null,
                    'level_id' => $q['level_id'] ?? $quiz->level_id ?? null,
                ];
                    // If there's an uploaded file for this question, store it and attach metadata
                    try {
                        $file = null;
                        // prefer numeric index key
                        if (is_array($mediaFiles) && array_key_exists($idx, $mediaFiles) && $mediaFiles[$idx]) {
                            $file = $mediaFiles[$idx];
                        }
                        // fallback to uid key if provided in question payload
                        elseif (isset($q['uid']) && is_array($mediaFiles) && array_key_exists($q['uid'], $mediaFiles) && $mediaFiles[$q['uid']]) {
                            $file = $mediaFiles[$q['uid']];
                        }

                        if ($file) {
                            $mPath = Storage::disk('public')->putFile('question_media', $file);
                            $mediaPath = Storage::url($mPath);
                            $mime = $file->getClientMimeType();
                            $mediaType = null;
                            if (strpos($mime, 'image/') === 0) $mediaType = 'image';
                            elseif (strpos($mime, 'audio/') === 0) $mediaType = 'audio';
                            elseif (strpos($mime, 'video/') === 0) $mediaType = 'video';
                            if ($mediaPath) {
                                $qData['media_path'] = $mediaPath;
                                $qData['media_type'] = $mediaType;
                            }
                        }
                    } catch (\Throwable $e) {
                        try { \Log::error('QuestionController@bulkUpdateForQuiz file storage failed', ['quiz_id' => $quiz->id, 'index' => $idx, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]); } catch (\Throwable $_) {}
                    }

                // If the question has an id, attempt update
                if (!empty($q['id'])) {
                    $existing = Question::where('id', $q['id'])->where('quiz_id', $quiz->id)->first();
                    if ($existing) {
                        $existing->fill($qData);
                        $incomingIds[] = $existing->id;
                            // If we stored media above, ensure existing question gets the path
                            if (isset($qData['media_path'])) $existing->media_path = $qData['media_path'];
                            if (isset($qData['media_type'])) $existing->media_type = $qData['media_type'];
                        $existing->save();
                        $saved[] = $existing;
                        continue;
                    }
                }
                    // Create new question
                    $qData['created_by'] = $user->id;
                    // apply auto-approve settings
                    $siteSettings = \App\Models\SiteSetting::current();
                    $siteAutoQuestions = $siteSettings ? (bool)$siteSettings->auto_approve_questions : true;
                    $qData['is_approved'] = $siteAutoQuestions;
                    $created = Question::create($qData);
                $incomingIds[] = $created->id;
                $saved[] = $created;
            } catch (\Throwable $e) {
                // ignore per-question failures but continue
            }
        }

        return ['saved' => $saved, 'incomingIds' => $incomingIds];
    }

    /**
     * Clean up after bulk update: load relations, delete old questions, recalc difficulty
     */
    private function cleanupAfterBulkUpdate(array &$saved, array $incomingIds, Quiz $quiz)
    {
        // Ensure returned saved questions include nested relations for client
        try {
            foreach ($saved as $s) {
                if ($s && method_exists($s, 'load')) {
                    $s->load(['grade.level', 'subject', 'topic', 'quiz']);
                }
            }
        } catch (\Throwable $_) {}

        // Delete questions that were part of the quiz but not in the incoming payload
        $existingIds = $quiz->questions()->pluck('id')->all();
        $toDeleteIds = array_diff($existingIds, $incomingIds);
        if (!empty($toDeleteIds)) {
            Question::whereIn('id', $toDeleteIds)->where('quiz_id', $quiz->id)->delete();
        }

        // recalc quiz difficulty
    try { $quiz->recalcDifficulty(); } catch (\Throwable $e) { try { \Log::error('QuestionController@bulkUpdateForQuiz recalcDifficulty failed', ['quiz_id' => $quiz->id, 'error' => $e->getMessage()]); } catch (\Throwable $_) {} }
    }

    /**
     * Bulk update/replace questions for a quiz. Expects { questions: [...] }
     * Frontend uses this to save all questions in one call.
     */
    public function bulkUpdateForQuiz(Request $request, Quiz $quiz)
    {
        if (!$this->canBulkUpdateQuiz($request, $quiz)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $items = $this->validateAndExtractQuestions($request);
        if ($items instanceof \Illuminate\Http\JsonResponse) {
            return $items; // validation failed, return error response
        }

        // collect any uploaded media files keyed under question_media[index] or question_media[uid]
        $mediaFiles = $request->file('question_media', []);
        $user = $request->user();

        $result = $this->processBulkQuestions($items, $mediaFiles, $quiz, $user);
        $saved = $result['saved'];
        $incomingIds = $result['incomingIds'];

        $this->cleanupAfterBulkUpdate($saved, $incomingIds, $quiz);

        return response()->json(['questions' => $saved]);
    }

    public function update(Request $request, Question $question)
    {
        $user = $request->user();
        if ($question->created_by !== $user->id && (!isset($user->is_admin) || !$user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'type' => 'nullable|string',
            'body' => 'nullable|string',
            'options' => 'nullable|array',
            'answers' => 'nullable|array',
            'parts' => 'nullable|array',
            'fill_parts' => 'nullable|array',
            'correct' => 'nullable',
            'corrects' => 'nullable|array',
            'marks' => 'nullable|numeric',
            'tags' => 'nullable|array',
            'solution_steps' => 'nullable|array',
            'subject_id' => 'nullable|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'nullable|exists:grades,id',
            'for_battle' => 'nullable|boolean',
            'is_quiz-master_marked' => 'nullable|boolean',
            'difficulty' => 'nullable|integer|min:1|max:5',
            'media' => 'nullable|file|max:10240',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        if ($request->hasFile('media')) {
            $path = Storage::disk('public')->putFile('question_media', $request->file('media'));
            $question->media_path = Storage::url($path);
        }

        // Expect canonical 'answers' array from frontend
        $payloadType = $request->input('type', $question->type);

        $answersInput = $request->has('answers') ? $request->input('answers') : $question->answers;
        if ($payloadType === 'fill_blank') {
            if (!is_array($answersInput)) {
                $answersInput = $answersInput ? [$answersInput] : [];
            }
            $answersNormalized = array_values(array_map(static function ($ans) {
                return is_null($ans) ? '' : (string) $ans;
            }, $answersInput ?? []));
        } else {
            if (is_array($answersInput)) {
                $answersNormalized = array_values(array_map(static fn($ans) => is_null($ans) ? null : (string) $ans, $answersInput));
            } elseif (!is_null($answersInput)) {
                $answersNormalized = [(string) $answersInput];
            } else {
                $answersNormalized = $question->answers ?? [];
            }
        }

        $fillPartsInput = $request->has('fill_parts') ? $request->input('fill_parts') : ($payloadType === 'fill_blank' ? ($question->fill_parts ?? $question->parts ?? []) : null);
        if ($payloadType === 'fill_blank') {
            if (!is_array($fillPartsInput)) {
                $fallbackParts = $request->has('parts') ? $request->input('parts') : $question->fill_parts;
                $fillPartsInput = is_array($fallbackParts) ? $fallbackParts : [];
            }
            $fillPartsNormalized = array_values(array_map(static function ($part) {
                if (is_array($part) && isset($part['text'])) {
                    return (string) $part['text'];
                }
                return is_string($part) ? $part : '';
            }, $fillPartsInput));
        } else {
            $fillPartsNormalized = null;
        }

        $partsInput = $request->has('parts') ? $request->input('parts') : $question->parts;
        if ($payloadType === 'fill_blank') {
            $partsNormalized = $fillPartsNormalized ?? [];
        } elseif ($payloadType === 'math') {
            if (!is_array($partsInput)) {
                $partsInput = [];
            }
            $partsNormalized = array_values(array_map(static function ($part) {
                if (is_array($part)) {
                    return [
                        'text' => isset($part['text']) ? (string) $part['text'] : '',
                        'marks' => isset($part['marks']) && is_numeric($part['marks']) ? (float) $part['marks'] : 0,
                    ];
                }
                return [
                    'text' => is_string($part) ? $part : '',
                    'marks' => 0,
                ];
            }, $partsInput));
        } else {
            if (!is_array($partsInput)) {
                $partsInput = [];
            }
            $partsNormalized = array_values(array_map(static function ($part) {
                if (is_array($part) && isset($part['text'])) {
                    return (string) $part['text'];
                }
                return is_string($part) ? $part : '';
            }, $partsInput));
        }

        $optionsInput = $request->has('options') ? $request->input('options') : $question->options;
        $optionsNormalized = $question->options;
        if (is_array($optionsInput)) {
            $optionsNormalized = array_values(array_map(function ($opt, $idx) use ($answersNormalized, $payloadType) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                } else {
                    $text = is_string($opt) ? $opt : '';
                }

                // Set is_correct based on answers array for option-based question types
                $isCorrect = false;
                if (in_array($payloadType, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq', 'multi'], true) && is_array($answersNormalized)) {
                    $isCorrect = in_array((string)$idx, $answersNormalized, true);
                }

                return [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                ];
            }, $optionsInput, array_keys($optionsInput)));
        }

        $marksNormalized = $question->marks;
        if ($request->has('marks')) {
            $marksInput = $request->input('marks');
            $marksNormalized = is_numeric($marksInput) ? (float) $marksInput : null;
        }

        $question->type = $payloadType;
        if ($request->has('body')) {
            $question->body = $request->get('body');
        }
        if (is_array($optionsNormalized) || is_null($optionsNormalized)) {
            $question->options = $optionsNormalized;
        }
        if ($payloadType === 'fill_blank') {
            $question->answers = $answersNormalized;
            $question->parts = $fillPartsNormalized ?? [];
            $question->fill_parts = $fillPartsNormalized ?? [];
        } else {
            $question->answers = $answersNormalized;
            $question->parts = $partsNormalized;
            $question->fill_parts = null;
        }
        if ($request->has('marks')) {
            $question->marks = $marksNormalized;
        }
        if ($request->has('difficulty')) {
            $question->difficulty = (int) $request->get('difficulty');
        }

        // additional fields (including level_id)
        foreach (['tags','solution_steps','subject_id','topic_id','grade_id','level_id','for_battle','is_quiz-master_marked','explanation'] as $f) {
            if ($request->has($f)) $question->{$f} = $request->get($f);
        }
        $question->save();

        if ($question->quiz_id) {
            try { $question->quiz->recalcDifficulty(); } catch (\Exception $e) {}
        }

        // Return with relations loaded
        try { $question->load(['grade.level', 'subject', 'topic', 'quiz']); } catch (\Throwable $_) {}
        return response()->json(['question' => $question]);
    }

    /**
     * Admin approves a question
     */
    public function approve(Request $request, Question $question)
    {
        $user = $request->user();
        // Prefer a dedicated isAdmin method, fallback to role column
        $isAdmin = method_exists($user, 'isAdmin') ? $user->isAdmin() : (($user->role ?? '') === 'admin');
        if (!$isAdmin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $question->is_approved = true;
        // clear approval request timestamp when approved
        if (Schema::hasColumn('questions', 'approval_requested_at')) {
            $question->approval_requested_at = null;
        }
        $question->save();

        return response()->json(['question' => $question]);
    }

    /**
     * Delete a question (owner or admin)
     */
    public function destroy(Request $request, Question $question)
    {
        $user = $request->user();
        if ($question->created_by !== $user->id && (!isset($user->is_admin) || !$user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        try {
            $question->delete();
            return response()->json(['message' => 'Deleted'], 200);
        } catch (\Throwable $e) {
            try { \Log::error('QuestionController@destroy failed', ['question_id' => $question->id ?? null, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]); } catch (\Throwable $_) {}
            return response()->json(['message' => 'Failed to delete'], 500);
        }
    }
}
