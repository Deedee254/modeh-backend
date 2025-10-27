<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
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
        $query = Question::query();

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

    // Allow callers to request a specific number of questions using
    // either `question_count` (used by some clients) or `per_page`.
    $perPage = max(1, (int)($request->get('question_count') ?? $request->get('per_page', 20)));
    return response()->json(['questions' => $query->paginate($perPage)]);
    }

    /**
     * Public question bank endpoint: returns global banked/random questions
     * with optional filters. This keeps quiz-master listing (`index`) separate.
     */
    public function bank(Request $request)
    {
        $query = Question::query();
        // The public question bank is independent of any quiz-master-set `for_battle` flag.
        // We intentionally do not filter by `for_battle` here.
        // Accept either explicit *_id keys or shorthand keys used by frontend (grade, subject, topic)
        $grade = $request->get('grade_id') ?? $request->get('grade');
        $subject = $request->get('subject_id') ?? $request->get('subject');
        $topic = $request->get('topic_id') ?? $request->get('topic');
        $difficulty = $request->get('difficulty');
        if ($grade) $query->where('grade_id', $grade);
        if ($subject) $query->where('subject_id', $subject);
        if ($topic) $query->where('topic_id', $topic);
        if ($difficulty) $query->where('difficulty', $difficulty);

        // Support filtering by level (frontend may send `level` or `level_id`). If provided,
        // constrain questions to grades that belong to that level (if grades table has level_id).
        $level = $request->get('level_id') ?? $request->get('level');
        if ($level) {
            try {
                if (Schema::hasTable('grades') && Schema::hasColumn('grades', 'level_id') && Schema::hasColumn('questions', 'grade_id')) {
                    $gradeIds = \App\Models\Grade::where('level_id', $level)->pluck('id')->toArray();
                    if (!empty($gradeIds)) {
                        $query->whereIn('grade_id', $gradeIds);
                    } else {
                        // no grades found for level — ensure no results
                        $query->whereRaw('0 = 1');
                    }
                }
            } catch (\Throwable $_) {
                // ignore and continue without level filtering
            }
        }

        if ($q = $request->get('q')) {
            $query->where(function($qq) use ($q) {
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
            $query->where('created_by', '!=', $user->id);
        }

        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderByDesc('id');
        }

        $perPage = max(1, (int)$request->get('per_page', 20));
        return response()->json(['questions' => $query->paginate($perPage)]);
    }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'quiz_id' => 'nullable|exists:quizzes,id',
            'type' => 'required|string|in:' . implode(',', array_keys(Question::getAllowedTypes())),
            'body' => 'required|string',
            'options' => 'nullable|array',
            'answers' => 'nullable|array',
            'parts' => 'nullable|array',
            'fill_parts' => 'nullable|array',
            'marks' => 'nullable|numeric',
            'correct' => 'nullable',
            'corrects' => 'nullable|array',
            'tags' => 'nullable|array',
            'hint' => 'nullable|string',
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
                // Get image dimensions
                try {
                    $dimensions = getimagesize($mediaFile->getPathname());
                    if ($dimensions) {
                        $mediaMetadata['width'] = $dimensions[0];
                        $mediaMetadata['height'] = $dimensions[1];
                    }
                } catch (\Exception $e) {}
            } elseif (strpos($mimeType, 'audio/') === 0) {
                $mediaType = 'audio';
                // Get audio duration if possible
                try {
                    $getID3 = new \getID3;
                    $fileInfo = $getID3->analyze($mediaFile->getPathname());
                    if (isset($fileInfo['playtime_seconds'])) {
                        $mediaMetadata['duration'] = $fileInfo['playtime_seconds'];
                    }
                } catch (\Exception $e) {}
            } elseif (strpos($mimeType, 'video/') === 0) {
                $mediaType = 'video';
                // Get video metadata if possible
                try {
                    $ffprobe = \FFMpeg\FFProbe::create();
                    $duration = $ffprobe
                        ->format($mediaFile->getPathname())
                        ->get('duration');
                    if ($duration) {
                        $mediaMetadata['duration'] = floatval($duration);
                    }
                } catch (\Exception $e) {}
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
        $correct = $request->get('correct');
        $corrects = $request->get('corrects');
        $fillParts = $request->get('fill_parts');

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
        }

        if ($payloadType === 'multi') {
            if (!is_array($corrects)) {
                $corrects = [];
            }
            $corrects = array_values(array_unique(array_map(static function ($idx) {
                return is_numeric($idx) ? (int) $idx : null;
            }, $corrects)));
            $corrects = array_values(array_filter($corrects, static fn($v) => $v !== null));
            $correct = null;
        } elseif (in_array($payloadType, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq'], true)) {
            $corrects = [];
            $correct = is_numeric($correct) ? (int) $correct : (is_numeric($request->input('answers.0')) ? (int) $request->input('answers.0') : null);
        } else {
            $correct = null;
            $corrects = [];
        }

        if ($payloadType !== 'fill_blank') {
            if (is_array($answers)) {
                $answers = array_values(array_map(static fn($ans) => is_null($ans) ? null : (string) $ans, $answers));
            } elseif (!is_null($answers)) {
                $answers = [(string) $answers];
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
            $options = array_values(array_map(function ($opt, $idx) use ($payloadType, $correct, $corrects) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                    $isCorrect = isset($opt['is_correct']) ? (bool) $opt['is_correct'] : false;
                } else {
                    $text = is_string($opt) ? $opt : '';
                    $isCorrect = false;
                }
                if (in_array($payloadType, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq'], true)) {
                    $isCorrect = ($correct === $idx);
                } elseif ($payloadType === 'multi') {
                    $isCorrect = in_array($idx, $corrects, true) || (!empty($corrects) && in_array((string) $idx, array_map('strval', $corrects), true));
                }
                return [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                ];
            }, $options));
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

        $question = Question::create([
            'quiz_id' => $request->quiz_id,
            'created_by' => $user->id,
            'type' => $request->type,
            'body' => $request->body,
            'options' => $options,
            'answers' => $answers ?? null,
            'parts' => $payloadType === 'fill_blank' ? $fillParts : $parts,
            'fill_parts' => $payloadType === 'fill_blank' ? $fillParts : null,
            'correct' => $correct,
            'corrects' => $corrects,
            'marks' => $marks,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'youtube_url' => $youtubeUrl,
            'media_metadata' => $mediaMetadata,
            'difficulty' => $request->get('difficulty', 3),
            'is_quiz-master_marked' => $request->get('is_quiz-master_marked', false),
            'is_approved' => $siteAutoQuestions,
            'tags' => $request->get('tags'),
            'hint' => $request->get('hint'),
            'solution_steps' => $request->get('solution_steps'),
            'subject_id' => $request->get('subject_id'),
            'topic_id' => $request->get('topic_id'),
            'grade_id' => $request->get('grade_id'),
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

        return response()->json(['question' => $question]);
    }

    /**
     * Create a question attached to a specific quiz (used by quiz-master UI)
     */
    public function storeForQuiz(Request $request, Quiz $quiz)
    {
        // merge quiz id into request then call store logic
        $request->merge(['quiz_id' => $quiz->id]);
        return $this->store($request);
    }

    /**
     * Bulk update/replace questions for a quiz. Expects { questions: [...] }
     * Frontend uses this to save all questions in one call.
     */
    public function bulkUpdateForQuiz(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        // minimal auth: only quiz owner or admin may bulk update
        if ($quiz->created_by && $quiz->created_by !== $user->id && !($user->is_admin ?? false)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

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

        // collect any uploaded media files keyed under question_media[index] or question_media[uid]
        $mediaFiles = $request->file('question_media', []);

        $saved = [];
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

                if (in_array($type, ['multi'], true)) {
                    if (!is_array($rawCorrects)) {
                        $rawCorrects = [];
                    }
                    $rawCorrects = array_values(array_unique(array_map(static function ($idx) {
                        return is_numeric($idx) ? (int) $idx : null;
                    }, $rawCorrects)));
                    $rawCorrects = array_values(array_filter($rawCorrects, static fn($v) => $v !== null));
                    $rawCorrect = null;
                } elseif (in_array($type, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq'], true)) {
                    $rawCorrects = [];
                    $rawCorrect = is_numeric($rawCorrect) ? (int) $rawCorrect : (is_numeric($rawAnswers[0] ?? null) ? (int) $rawAnswers[0] : null);
                } else {
                    $rawCorrect = null;
                    $rawCorrects = [];
                }

                if (is_array($rawOptions)) {
                    $rawOptions = array_values(array_map(function ($opt, $idx) use ($type, $rawCorrect, $rawCorrects) {
                        if (is_array($opt)) {
                            $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                            $isCorrect = isset($opt['is_correct']) ? (bool) $opt['is_correct'] : false;
                        } else {
                            $text = is_string($opt) ? $opt : '';
                            $isCorrect = false;
                        }
                        if (in_array($type, ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq'], true)) {
                            $isCorrect = ($rawCorrect === $idx);
                        } elseif ($type === 'multi') {
                            $isCorrect = in_array($idx, $rawCorrects, true) || (!empty($rawCorrects) && in_array((string) $idx, array_map('strval', $rawCorrects), true));
                        }
                        return [
                            'text' => $text,
                            'is_correct' => $isCorrect,
                        ];
                    }, $rawOptions));
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
                    'options' => $rawOptions,
                    'answers' => $type === 'fill_blank' ? $rawAnswers : (is_array($rawAnswers) ? array_values(array_map(static fn($ans) => is_null($ans) ? null : (string) $ans, $rawAnswers)) : (!is_null($rawAnswers) ? [(string) $rawAnswers] : [])),
                    'parts' => $type === 'fill_blank' ? $rawFillParts : $rawParts,
                    'fill_parts' => $type === 'fill_blank' ? $rawFillParts : null,
                    'correct' => $rawCorrect,
                    'corrects' => $rawCorrects,
                    'marks' => $marks,
                    'difficulty' => $q['difficulty'] ?? 3,
                    'tags' => $q['tags'] ?? null,
                    'hint' => $q['hint'] ?? null,
                    'solution_steps' => $q['solution_steps'] ?? null,
                    'subject_id' => $q['subject_id'] ?? $quiz->subject_id ?? null,
                    'topic_id' => $q['topic_id'] ?? $quiz->topic_id ?? null,
                    'grade_id' => $q['grade_id'] ?? $quiz->grade_id ?? null,
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
                    } catch (\Exception $e) {
                        // ignore file storage errors for per-question uploads and continue
                    }

                // If the question has an id, attempt update
                if (!empty($q['id'])) {
                    $existing = Question::where('id', $q['id'])->where('quiz_id', $quiz->id)->first();
                    if ($existing) {
                        $existing->fill($qData);
                        if (isset($qData['answers'])) $existing->answers = $qData['answers'];
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
                $saved[] = $created;
            } catch (\Throwable $e) {
                // ignore per-question failures but continue
            }
        }

        // recalc quiz difficulty
        try { $quiz->recalcDifficulty(); } catch (\Exception $e) {}

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
            'hint' => 'nullable|string',
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
        $singleChoiceTypes = ['mcq', 'image_mcq', 'audio_mcq', 'video_mcq'];

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

        $correctInput = $request->has('correct') ? $request->input('correct') : $question->correct;
        $correctsInput = $request->has('corrects') ? $request->input('corrects') : $question->corrects;
        if ($payloadType === 'multi') {
            $correctsInput = is_array($correctsInput) ? $correctsInput : [];
            $correctsNormalized = array_values(array_filter(array_map(static function ($idx) {
                return is_numeric($idx) ? (int) $idx : null;
            }, $correctsInput), static fn($v) => $v !== null));
            $correctNormalized = null;
        } elseif (in_array($payloadType, $singleChoiceTypes, true)) {
            if (is_numeric($correctInput)) {
                $correctNormalized = (int) $correctInput;
            } elseif (isset($answersNormalized[0]) && is_numeric($answersNormalized[0])) {
                $correctNormalized = (int) $answersNormalized[0];
            } elseif (is_numeric($question->correct)) {
                $correctNormalized = (int) $question->correct;
            } else {
                $correctNormalized = null;
            }
            $correctsNormalized = [];
        } else {
            $correctNormalized = null;
            $correctsNormalized = [];
        }

        $optionsInput = $request->has('options') ? $request->input('options') : $question->options;
        $optionsNormalized = $question->options;
        if (is_array($optionsInput)) {
            $optionsNormalized = [];
            $idx = 0;
            foreach ($optionsInput as $opt) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['value']) ? (string) $opt['value'] : '');
                    $isCorrect = isset($opt['is_correct']) ? (bool) $opt['is_correct'] : false;
                } else {
                    $text = is_string($opt) ? $opt : '';
                    $isCorrect = false;
                }
                if (in_array($payloadType, $singleChoiceTypes, true)) {
                    $isCorrect = ($correctNormalized === $idx);
                } elseif ($payloadType === 'multi') {
                    $isCorrect = in_array($idx, $correctsNormalized, true);
                }
                $optionsNormalized[] = [
                    'text' => $text,
                    'is_correct' => $isCorrect,
                ];
                $idx++;
            }
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
            $question->correct = null;
            $question->corrects = [];
        } else {
            if ($request->has('answers')) {
                $question->answers = $answersNormalized;
            }
            $question->parts = $partsNormalized;
            $question->fill_parts = null;
            if ($payloadType === 'multi') {
                $question->correct = null;
                $question->corrects = $correctsNormalized;
            } elseif (in_array($payloadType, $singleChoiceTypes, true)) {
                $question->correct = $correctNormalized;
                $question->corrects = [];
            } else {
                $question->correct = null;
                $question->corrects = [];
            }
        }
        if ($request->has('marks')) {
            $question->marks = $marksNormalized;
        }
        if ($request->has('difficulty')) {
            $question->difficulty = (int) $request->get('difficulty');
        }

        // additional fields
        foreach (['tags','hint','solution_steps','subject_id','topic_id','grade_id','for_battle','is_quiz-master_marked'] as $f) {
            if ($request->has($f)) $question->{$f} = $request->get($f);
        }
        $question->save();

        if ($question->quiz_id) {
            try { $question->quiz->recalcDifficulty(); } catch (\Exception $e) {}
        }

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
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete'], 500);
        }
    }
}
