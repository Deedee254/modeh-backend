<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\AchievementService;
use App\Models\Topic;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\SiteSetting;
use App\Models\Question;
use App\Http\Resources\QuizResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class QuizController extends Controller
{
    protected array $questionColumnCache = [];
    protected $achievementService;

    public function __construct()
    {
        // Protect most endpoints but allow public listing (index)
        $this->middleware('auth:sanctum')->except(['index']);
        $this->achievementService = app(AchievementService::class);
    }

    // Update existing quiz (used by quiz-master UI to save settings and publish)
    public function update(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        // Only allow owner or admin to update
        if ($quiz->created_by && $quiz->created_by !== $user->id && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Eager load relationships that will be needed in updateTaxonomy
        $quiz->load(['subject.grade', 'topic.subject.grade']);

        $this->normalizeUpdateRequestData($request);

        $v = $this->validateUpdateRequest($request);
        if ($v->fails()) {
            try {
                \Log::error('QuizController@update validation failed', ['errors' => $v->errors()->toArray(), 'payload' => $request->all()]);
            } catch (\Throwable $_) {
            }
            return response()->json(['errors' => $v->errors()], 422);
        }

        $this->handleCoverUploadForUpdate($quiz, $request);
        $this->updateBasicFields($quiz, $request);

        $taxonomyResult = $this->updateTaxonomy($quiz, $request);
        if ($taxonomyResult instanceof \Illuminate\Http\JsonResponse) {
            return $taxonomyResult;
        }
        $topic = $taxonomyResult['topic'];

        $this->processQuestionsForUpdate($quiz, $user, $request, $topic);

        if ($request->has('level_id')) {
            $quiz->level_id = $request->get('level_id');
        }

        $this->finalizeQuizUpdate($quiz);

        return response()->json($quiz);
    }

    private function normalizeUpdateRequestData(Request $request): void
    {
        // Normalize empty-string inputs (common from browser selects) to null for nullable numeric fields
        foreach (['subject_id', 'grade_id', 'timer_seconds', 'per_question_seconds', 'attempts_allowed', 'scheduled_at'] as $k) {
            if ($request->has($k) && $request->get($k) === '') {
                $request->merge([$k => null]);
            }
        }

        // If questions were sent as JSON string inside multipart/form-data, decode them
        if ($request->has('questions') && is_string($request->get('questions'))) {
            $decoded = json_decode($request->get('questions'), true);
            if (is_array($decoded)) {
                $request->merge(['questions' => $decoded]);
            }
        }

        $this->normalizeBooleanInputs($request, ['is_paid', 'use_per_question_timer', 'shuffle_questions', 'shuffle_answers', 'is_draft']);

        // Log incoming payload for debugging (don't include file streams)
        \Log::info('QuizController@update incoming', array_merge($request->except(['cover', 'question_media']), ['files' => array_keys($request->files->all())]));
    }

    private function validateUpdateRequest(Request $request): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'topic_id' => 'sometimes|nullable|exists:topics,id',
            'subject_id' => 'sometimes|nullable|exists:subjects,id',
            'grade_id' => 'sometimes|nullable|exists:grades,id',
            'level_id' => 'sometimes|nullable|exists:levels,id',
            'description' => 'sometimes|nullable|string',
            'youtube_url' => 'sometimes|nullable|url',
            'is_paid' => 'sometimes|boolean',
            'one_off_price' => 'sometimes|nullable|numeric|min:0',
            'timer_seconds' => 'sometimes|nullable|integer|min:0',
            'per_question_seconds' => 'sometimes|nullable|integer|min:10',
            'use_per_question_timer' => 'sometimes|boolean',
            'attempts_allowed' => 'sometimes|nullable|integer|min:0',
            'shuffle_questions' => 'sometimes|boolean',
            'shuffle_answers' => 'sometimes|boolean',
            'visibility' => 'sometimes|string|in:draft,published,scheduled',
            'scheduled_at' => 'sometimes|nullable|date',
            'is_draft' => 'sometimes|boolean',
            'questions' => 'sometimes|array',
            'cover' => 'sometimes|file|image|max:5120',
        ]);
    }

    private function handleCoverUploadForUpdate(Quiz $quiz, Request $request): void
    {
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $path = Storage::disk('public')->putFile('covers', $file);
            $quiz->cover_image = Storage::url($path);
        }
    }

    private function updateBasicFields(Quiz $quiz, Request $request): void
    {
        $fields = ['title', 'description', 'youtube_url', 'timer_seconds', 'per_question_seconds', 'use_per_question_timer', 'attempts_allowed', 'shuffle_questions', 'shuffle_answers', 'visibility', 'scheduled_at', 'is_draft', 'one_off_price'];
        foreach ($fields as $f) {
            if ($request->has($f)) {
                $quiz->{$f} = $request->get($f);
            }
        }
    }

    /**
     * @return array{topic: Topic|null}|\Illuminate\Http\JsonResponse
     */
    private function updateTaxonomy(Quiz $quiz, Request $request)
    {
        $newTopic = null;

        // Ensure quiz has required relationships loaded for safe access
        if (!$quiz->relationLoaded('subject')) {
            $quiz->load('subject.grade');
        }
        if (!$quiz->relationLoaded('topic')) {
            $quiz->load('topic.subject');
        }

        // allow updating taxonomy links — validate consistency if topic/subject/grade are changed
        if ($request->has('topic_id')) {
            if ($request->has('access')) {
                $quiz->is_paid = $request->get('access') === 'paywall';
            } elseif ($request->has('is_paid')) {
                $quiz->is_paid = $request->boolean('is_paid');
            }
            // Load subject->grade to allow safe inference of subject/grade/level
            $newTopic = Topic::with(['subject.grade'])->find($request->get('topic_id'));
            if (!$newTopic) {
                return response()->json(['message' => 'Topic not found'], 422);
            }
            // If the caller also supplied a subject_id, ensure it matches the topic's subject
            $topicSubjectId = $newTopic->subject_id ?? $newTopic->subject?->id ?? null;
            if ($request->has('subject_id') && (string) $request->get('subject_id') !== (string) $topicSubjectId) {
                return response()->json(['message' => 'Supplied topic does not belong to the supplied subject'], 422);
            }
            $quiz->topic_id = $newTopic->id;
            // Use explicit FK if present, otherwise fall back to loaded relation ids
            // Direct assignment from payload as single source of truth - no inference fallbacks
            if ($request->has('subject_id'))
                $quiz->subject_id = $request->get('subject_id');
            if ($request->has('grade_id'))
                $quiz->grade_id = $request->get('grade_id');
            if ($request->has('level_id'))
                $quiz->level_id = $request->get('level_id');
        } elseif ($request->has('subject_id')) {
            // prefer imported Subject model (avoids fully-qualified names)
            $newSubject = Subject::find($request->get('subject_id'));
            if (!$newSubject) {
                return response()->json(['message' => 'Subject not found'], 422);
            }
            $quiz->subject_id = $newSubject->id;

            // Direct assignment from payload as single source of truth - no inference fallbacks
            if ($request->has('grade_id'))
                $quiz->grade_id = $request->get('grade_id');
            if ($request->has('level_id'))
                $quiz->level_id = $request->get('level_id');

            // If the new subject is different from the old one, nullify the topic
            if ($quiz->topic && (($quiz->topic->subject_id ?? $quiz->topic->subject?->id ?? null) !== $newSubject->id)) {
                $quiz->topic_id = null;
            }
        } elseif ($request->has('grade_id')) {
            $newGrade = Grade::find($request->get('grade_id'));
            if (!$newGrade) {
                return response()->json(['message' => 'Grade not found'], 422);
            }
            $quiz->grade_id = $newGrade->id;
            $quiz->level_id = $newGrade->level_id;
            // If the new grade is different, nullify subject and topic
            if ($quiz->subject && (($quiz->subject->grade_id ?? $quiz->subject->grade?->id ?? null) !== $newGrade->id)) {
                $quiz->subject_id = null;
                $quiz->topic_id = null;
            }
        }

        // Ensure $topic is set for question-related logic below. If a new topic was supplied
        // earlier we used $newTopic; prefer that otherwise fall back to the quiz's current topic.
        $topic = null;
        if ($newTopic) {
            $topic = $newTopic;
        } else {
            // $quiz->topic may be null if relation not loaded, try to load it
            $topic = $quiz->topic ?? Topic::with(['subject'])->find($quiz->topic_id);
        }

        return ['topic' => $topic];
    }

    private function processQuestionsForUpdate(Quiz $quiz, $user, Request $request, $topic): void
    {
        if (!$request->filled('questions') || !is_array($request->questions)) {
            return;
        }

        try {
            \Log::info('QuizController@update received questions payload', [
                'quiz_id' => $quiz->id,
                'questions_count' => is_array($request->questions) ? count($request->questions) : null,
                'files' => array_keys($request->files->all()),
            ]);
        } catch (\Throwable $_) {
        }

        // Support per-question file uploads: keys may be numeric index or question uid
        $mediaFiles = $request->file('question_media', []);
        foreach ($request->questions as $index => $q) {
            try {
                $this->createQuestionForUpdate($quiz, $user, $q, $index, $mediaFiles, $topic);
            } catch (\Throwable $e) {
                try {
                    \Log::error('QuizController@update question failed', [
                        'quiz_id' => $quiz->id,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'payload_preview' => is_array($q) ? array_slice($q, 0, 10) : $q,
                    ]);
                } catch (\Throwable $_) {
                }
            }
        }

        try {
            $quiz->recalcDifficulty();
        } catch (\Exception $e) {
        }
    }

    private function createQuestionForUpdate(Quiz $quiz, $user, array $q, $index, array $mediaFiles, $topic): void
    {
        $qType = $q['type'] ?? 'mcq';
        // Prefer canonical 'body' field; accept legacy 'text' as fallback
        $body = $q['body'] ?? ($q['text'] ?? '');
        $options = $q['options'] ?? null;
        $answers = $q['answers'] ?? (isset($q['correct']) ? [$q['correct']] : null);

        $mediaPath = null;
        $mediaType = null;
        $file = null;
        // prefer numeric index key
        if (is_array($mediaFiles) && array_key_exists($index, $mediaFiles) && $mediaFiles[$index]) {
            $file = $mediaFiles[$index];
        }
        // fallback to uid key if provided in question payload
        elseif (isset($q['uid']) && is_array($mediaFiles) && array_key_exists($q['uid'], $mediaFiles) && $mediaFiles[$q['uid']]) {
            $file = $mediaFiles[$q['uid']];
        }
        // if we have a file, store it
        if ($file) {
            $mPath = Storage::disk('public')->putFile('question_media', $file);
            $mediaPath = Storage::url($mPath);
            $mediaType = $file->getClientMimeType();
        }

        $siteSettings = SiteSetting::current();
        $siteAutoQuestions = $siteSettings ? (bool) $siteSettings->auto_approve_questions : true;
        // If topic/subject information is unavailable, default to site setting
        $questionIsApproved = $siteAutoQuestions || (($topic && $topic->subject) ? (bool) ($topic->subject->auto_approve ?? false) : false);

        // Determine correct/corrects for MCQ/MULTI types when frontend provided them
        $qCorrect = null;
        $qCorrects = [];
        if (isset($q['correct']) && is_numeric($q['correct'])) {
            $qCorrect = (int) $q['correct'];
        } elseif (is_array($answers) && isset($answers[0]) && is_numeric($answers[0])) {
            // fallback: answers[0] may contain the correct index
            $qCorrect = (int) $answers[0];
        }
        if (isset($q['corrects']) && is_array($q['corrects'])) {
            $qCorrects = array_values(array_filter(array_map(static function ($c) {
                return is_numeric($c) ? (int) $c : null;
            }, $q['corrects']), static fn($v) => $v !== null));
        }

        $questionData = [
            'quiz_id' => $quiz->id,
            // User requested to respect creation payload if present (e.g. admin masquerading or explicit set), otherwise fallback to auth user
            'created_by' => $q['created_by'] ?? $user->id,
            'type' => $qType,
            'body' => $body,
            'explanation' => $q['explanation'] ?? null,
            'youtube_url' => $q['youtube_url'] ?? null,
            'media_metadata' => $q['media_metadata'] ?? null,
            'options' => $options,
            'answers' => $answers,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'difficulty' => $q['difficulty'] ?? 3,
            'marks' => isset($q['marks']) ? $q['marks'] : null,
            'is_quiz-master_marked' => true,
            'is_approved' => $questionIsApproved,
            'is_banked' => isset($q['is_banked']) ? (bool) $q['is_banked'] : false,
            'level_id' => $quiz->level_id ?? $q['level_id'],
            'grade_id' => $quiz->grade_id ?? $q['grade_id'],
            'subject_id' => $quiz->subject_id ?? $q['subject_id'],
            'topic_id' => $quiz->topic_id ?? $q['topic_id'],
        ];

        if ($this->questionsTableHasColumn('correct')) {
            $questionData['correct'] = $qCorrect;
        }

        if ($this->questionsTableHasColumn('corrects')) {
            $questionData['corrects'] = $qCorrects;
        }

        $createdQuestion = Question::create($questionData);
        try {
            \Log::info('QuizController@update question created', [
                'quiz_id' => $quiz->id,
                'question_id' => $createdQuestion->id ?? null,
                'created_grade_id' => $createdQuestion->grade_id,
                'expected_grade_id' => $quiz->grade_id,
                'index' => $index,
                'payload_keys' => is_array($q) ? array_values(array_keys($q)) : null,
            ]);
        } catch (\Throwable $_) {
        }
    }

    private function finalizeQuizUpdate(Quiz $quiz): void
    {
        $quiz->save();

        // Ensure relations and nested level are loaded for client
        $quiz->load(['grade.level', 'subject', 'topic']);

        // Trigger achievement checks for updating a quiz (in case level changed)
        try {
            if ($this->achievementService) {
                $this->achievementService->checkAchievements(auth()->id(), [
                    'type' => 'quiz_updated',
                    'quiz_id' => $quiz->id,
                    'level_id' => $quiz->level_id,
                    'grade_id' => $quiz->grade_id,
                    'subject_id' => $quiz->subject_id,
                ]);
            }
        } catch (\Throwable $_) {
        }
    }

    // Paginated list for quizzes with search and filter support
    public function index(Request $request)
    {
        $user = $request->user();
        // Eager-load topic->subject, grade, and level so frontend can access data directly
        // and include a questions_count and attempts_count for each quiz using withCount
        $query = Quiz::query()
            ->with(['topic.subject', 'grade', 'level'])
            ->withCount(['questions', 'attempts']);

        if ($user) {
            $query->with('userLastAttempt')
                  ->withExists(['likes as liked' => function($q) use ($user) {
                      $q->where('user_id', $user->id);
                  }]);
        }

        // search
        if ($q = $request->get('q')) {
            $query->where('title', 'like', "%{$q}%");
        }

        // If the request is anonymous, show only approved & published quizzes
        if (!$user) {
            $query->where('is_approved', true)->where('visibility', 'published');
        } else {
            // only my quizzes unless admin
            if (!$user->is_admin) {
                $query->where('created_by', $user->id);
            }
        }

        // filter by topic or approved (explicit query overrides defaults)
        if ($topic = $request->get('topic_id')) {
            $query->where('topic_id', $topic);
        }
        // filter by subject_id explicitly
        if ($subjectId = $request->get('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        // filter by level (via the quiz's grade's level_id)
        if ($levelId = $request->get('level_id')) {
            $query->whereHas('grade', function ($q) use ($levelId) {
                $q->where('level_id', $levelId);
            });
        }
        // filter by grade_id explicitly
        if ($gradeId = $request->get('grade_id')) {
            $query->where('grade_id', $gradeId);
        }
        // filter by paid/free status (frontend can pass is_paid=0 or is_paid=1)
        if ($request->has('is_paid')) {
            $query->where('is_paid', $request->boolean('is_paid'));
        }
        if (!is_null($request->get('approved'))) {
            $query->where('is_approved', (bool) $request->get('approved'));
        }

        // sorting
        $sort = $request->get('sort', 'newest');
        switch ($sort) {
            case 'popular':
            case 'attempted':
                $query->orderBy('attempts_count', 'desc');
                break;
            case 'most_liked':
                $query->orderBy('likes_count', 'desc');
                break;
            case 'shortest':
                $query->orderBy('timer_seconds', 'asc');
                break;
            case 'difficulty':
                $query->orderBy('difficulty', 'desc');
                break;
            case 'featured':
                $query->where('is_approved', true)->orderBy('created_at', 'desc'); // placeholders for featured
                break;
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $perPage = max(1, (int) $request->get('per_page', 10));

        $cacheKey = 'quizzes_index_' . md5(serialize($request->all()) . ($user ? $user->id : 'guest'));
        
        $data = Cache::remember($cacheKey, now()->addMinutes(5), function() use ($query, $perPage) {
            return $query->paginate($perPage);
        });

        return QuizResource::collection($data);
    }

    private function normalizeBooleanInputs(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (!$request->has($key)) {
                continue;
            }

            $value = $request->input($key);

            if ($value === '' || $value === null) {
                $request->merge([$key => null]);
                continue;
            }

            if (is_bool($value)) {
                continue;
            }

            if (is_numeric($value)) {
                $request->merge([$key => (bool) (int) $value]);
                continue;
            }

            if (is_string($value)) {
                $normalized = strtolower($value);

                if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                    $request->merge([$key => true]);
                    continue;
                }

                if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
                    $request->merge([$key => false]);
                    continue;
                }

                if ($normalized === 'null') {
                    $request->merge([$key => null]);
                    continue;
                }
            }
        }
    }

    private function questionsTableHasColumn(string $column): bool
    {
        if (!array_key_exists($column, $this->questionColumnCache)) {
            $this->questionColumnCache[$column] = Schema::hasColumn('questions', $column);
        }

        return $this->questionColumnCache[$column];
    }

    private function validateAndPrepareStoreData(Request $request)
    {
        $user = $request->user();

        // Normalize empty-string inputs (common from browser selects) to null for nullable numeric fields
        foreach (['subject_id', 'grade_id', 'timer_seconds', 'per_question_seconds', 'attempts_allowed', 'scheduled_at'] as $k) {
            if ($request->has($k) && $request->get($k) === '') {
                $request->merge([$k => null]);
            }
        }

        // If questions were sent as JSON string inside multipart/form-data, decode them
        if ($request->has('questions') && is_string($request->get('questions'))) {
            $decoded = json_decode($request->get('questions'), true);
            if (is_array($decoded)) {
                $request->merge(['questions' => $decoded]);
            }
        }

        $this->normalizeBooleanInputs($request, ['is_paid', 'use_per_question_timer', 'shuffle_questions', 'shuffle_answers', 'is_draft']);

        // Log incoming payload for debugging (don't include file streams)
        \Log::info('QuizController@store incoming', array_merge($request->except(['cover', 'question_media']), ['files' => array_keys($request->files->all())]));

        // Temporary: attempt to dump full request payload for debugging. Wrapped in try/catch
        // because uploaded file objects may not be serializable in logs.
        try {
            \Log::debug('QuizController@store full request dump', [
                'all' => $request->all(),
                'files' => array_keys($request->files->all()),
            ]);
        } catch (\Throwable $e) {
            try {
                \Log::error('QuizController@store dump failed', ['error' => $e->getMessage()]);
            } catch (\Throwable $_) {
            }
        }
        $v = Validator::make($request->all(), [
            'topic_id' => 'required|exists:topics,id',
            'subject_id' => 'required|exists:subjects,id',
            'grade_id' => 'required|exists:grades,id',
            'level_id' => 'nullable|exists:levels,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'youtube_url' => 'nullable|url',
            'is_paid' => 'boolean',
            'one_off_price' => 'nullable|numeric|min:0',
            'timer_seconds' => 'nullable|integer|min:1',
            'per_question_seconds' => 'nullable|integer|min:10',
            'use_per_question_timer' => 'nullable|boolean',
            'attempts_allowed' => 'nullable|integer|min:1',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_answers' => 'nullable|boolean',
            'visibility' => 'nullable|string|in:draft,published,scheduled',
            'scheduled_at' => 'nullable|date',
            'is_draft' => 'nullable|boolean',
            'questions' => 'nullable|array',
            'cover' => 'nullable|file|image|max:5120', // max 5MB
        ]);

        if ($v->fails()) {
            try {
                \Log::error('QuizController@store validation failed', ['errors' => $v->errors()->toArray(), 'payload' => $request->all()]);
            } catch (\Throwable $_) {
            }
            return response()->json(['errors' => $v->errors()], 422);
        }

        $topic = Topic::find($request->topic_id);
        if (!$topic || !$topic->is_approved) {
            return response()->json(['message' => 'Topic is not approved or does not exist'], 403);
        }

        // If the frontend provided a subject_id, ensure the selected topic belongs to it.
        // We do not infer or merge subject/grade/level here — the frontend's payload
        // (buildQuizPayload) is expected to include canonical IDs.
        $providedSubjectId = $request->get('subject_id');
        if ($providedSubjectId) {
            $topicSubjectId = $topic->subject_id ?? $topic->subject?->id ?? null;
            if ((string) $topicSubjectId !== (string) $providedSubjectId) {
                return response()->json(['message' => 'Topic does not belong to the supplied subject'], 422);
            }
        }

        // If a subject_id was provided, validate the referenced subject exists and,
        // if a grade_id was provided, ensure consistency. Do not infer missing ids.
        $subject = null;
        if ($request->has('subject_id')) {
            $subject = Subject::find($request->get('subject_id'));
            if (!$subject) {
                return response()->json(['message' => 'Subject not found'], 422);
            }
            if ($request->has('grade_id')) {
                $subjectGradeId = $subject->grade_id ?? $subject->grade?->id ?? null;
                if ((string) $subjectGradeId !== (string) $request->get('grade_id')) {
                    return response()->json(['message' => 'Subject does not belong to the supplied grade'], 422);
                }
            }
        }

        return [
            'user' => $user,
            'topic' => $topic,
            'subject' => $subject,
            'request' => $request,
        ];
    }

    private function handleCoverUpload(Request $request): ?string
    {
        $coverUrl = null;
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $path = Storage::disk('public')->putFile('covers', $file);
            $coverUrl = Storage::url($path);
        }
        return $coverUrl;
    }

    private function createQuiz($user, $topic, Request $request, ?string $coverUrl)
    {
        // Use IDs directly from the frontend payload. The frontend's
        // buildQuizPayload should supply topic_id, subject_id, grade_id and
        // level_id; do not second-guess or infer server-side.
        $quiz = Quiz::create([
            'user_id' => $user->id,
            'topic_id' => $request->get('topic_id') ?? $topic->id,
            'subject_id' => $request->get('subject_id') ?? null,
            'grade_id' => $request->get('grade_id') ?? null,
            'level_id' => $request->get('level_id') ?? null,
            'created_by' => $user->id,
            'title' => $request->title,
            'description' => $request->description,
            'youtube_url' => $request->youtube_url,
            'cover_image' => $coverUrl,
            // if frontend sent 'access' === 'paywall' treat as paid
            'is_paid' => $request->get('access') === 'paywall' ? true : $request->get('is_paid', false),
            'one_off_price' => $request->get('one_off_price') ?? null,
            'timer_seconds' => $request->timer_seconds ?? null,
            'per_question_seconds' => $request->get('per_question_seconds') ?? null,
            'use_per_question_timer' => (bool) $request->get('use_per_question_timer', false),
            'attempts_allowed' => $request->get('attempts_allowed') ?? null,
            'shuffle_questions' => (bool) $request->get('shuffle_questions', false),
            'shuffle_answers' => (bool) $request->get('shuffle_answers', false),
            'visibility' => $request->get('visibility', 'published'),
            'scheduled_at' => $request->get('scheduled_at') ? \Carbon\Carbon::parse($request->get('scheduled_at')) : null,
            'is_approved' => false,
            'is_draft' => $request->get('is_draft', false),
        ]);

        // If level_id wasn't supplied explicitly, try to infer from grade
        if (!$quiz->level_id && $quiz->grade_id) {
            try {
                $quiz->level_id = Grade::find($quiz->grade_id)->level_id ?? null;
                $quiz->save();
            } catch (\Throwable $_) {
            }
        }

        // Load relations for frontend consumption (grade with level, subject, topic)
        $quiz->load(['grade.level', 'subject', 'topic']);

        // Trigger achievement checks for creating a quiz (allows achievement rules to inspect level_id/grade_id/subject_id)
        try {
            if ($this->achievementService) {
                $this->achievementService->checkAchievements($user->id, [
                    'type' => 'quiz_created',
                    'quiz_id' => $quiz->id,
                    'level_id' => $quiz->level_id,
                    'grade_id' => $quiz->grade_id,
                    'subject_id' => $quiz->subject_id,
                ]);
            }
        } catch (\Throwable $_) {
        }
        // If subject/topic auto-approve and settings allow, set approved
        if ($topic->subject?->auto_approve) {
            $quiz->is_approved = true;
            $quiz->save();
        }

        return $quiz;
    }

    private function createQuestionsForQuiz($quiz, $user, Request $request): void
    {
        if (!$request->filled('questions') || !is_array($request->questions)) {
            return;
        }

        $mediaFiles = $request->file('question_media', []);
        foreach ($request->questions as $index => $q) {
            try {
                $qType = $q['type'] ?? 'mcq';
                $body = $q['body'] ?? ($q['text'] ?? '');
                $options = $q['options'] ?? null;
                $answers = $q['answers'] ?? (isset($q['correct']) ? [$q['correct']] : null);

                $mediaPath = null;
                $mediaType = null;
                $file = null;
                if (is_array($mediaFiles) && array_key_exists($index, $mediaFiles) && $mediaFiles[$index]) {
                    $file = $mediaFiles[$index];
                } elseif (isset($q['uid']) && is_array($mediaFiles) && array_key_exists($q['uid'], $mediaFiles) && $mediaFiles[$q['uid']]) {
                    $file = $mediaFiles[$q['uid']];
                }
                if ($file) {
                    $mPath = Storage::disk('public')->putFile('question_media', $file);
                    $mediaPath = Storage::url($mPath);
                    $mediaType = $file->getClientMimeType();
                }

                $isBanked = isset($q['is_banked']) ? (bool) $q['is_banked'] : false;

                $qCorrect = null;
                $qCorrects = [];
                if (isset($q['correct']) && is_numeric($q['correct'])) {
                    $qCorrect = (int) $q['correct'];
                } elseif (is_array($answers) && isset($answers[0]) && is_numeric($answers[0])) {
                    $qCorrect = (int) $answers[0];
                }
                if (isset($q['corrects']) && is_array($q['corrects'])) {
                    $qCorrects = array_values(array_filter(array_map(static function ($c) {
                        return is_numeric($c) ? (int) $c : null;
                    }, $q['corrects']), static fn($v) => $v !== null));
                }

                $questionData = [
                    'quiz_id' => $quiz->id,
                    'created_by' => $q['created_by'] ?? $user->id,
                    'type' => $qType,
                    'body' => $body,
                    'explanation' => $q['explanation'] ?? null,
                    'youtube_url' => $q['youtube_url'] ?? null,
                    'media_metadata' => $q['media_metadata'] ?? null,
                    'options' => $options,
                    'answers' => $answers,
                    'media_path' => $mediaPath,
                    'media_type' => $mediaType,
                    'difficulty' => $q['difficulty'] ?? 3,
                    'marks' => isset($q['marks']) ? $q['marks'] : null,
                    'is_quiz-master_marked' => true,
                    'is_approved' => false,
                    'is_banked' => $isBanked,
                    'level_id' => $quiz->level_id,
                    'grade_id' => $quiz->grade_id,
                    'subject_id' => $quiz->subject_id,
                    'topic_id' => $quiz->topic_id,
                ];

                if ($this->questionsTableHasColumn('correct')) {
                    $questionData['correct'] = $qCorrect;
                }

                if ($this->questionsTableHasColumn('corrects')) {
                    $questionData['corrects'] = $qCorrects;
                }

                $createdQuestion = Question::create($questionData);
                try {
                    \Log::info('QuizController@store question created', [
                        'quiz_id' => $quiz->id,
                        'question_id' => $createdQuestion->id ?? null,
                        'created_grade_id' => $createdQuestion->grade_id,
                        'expected_grade_id' => $quiz->grade_id,
                        'index' => $index,
                        'payload_keys' => is_array($q) ? array_values(array_keys($q)) : null,
                    ]);
                } catch (\Throwable $_) {
                }
            } catch (\Throwable $e) {
                try {
                    \Log::error('QuizController@store question failed', [
                        'quiz_id' => $quiz->id,
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'payload_preview' => is_array($q) ? array_slice($q, 0, 10) : $q,
                    ]);
                } catch (\Throwable $_) {
                }
            }
        }

        try {
            $quiz->recalcDifficulty();
        } catch (\Exception $e) {
        }
    }

    // quiz-master creates a quiz under a topic (topic must be approved)
    public function store(Request $request)
    {
        $prepared = $this->validateAndPrepareStoreData($request);
        if ($prepared instanceof \Illuminate\Http\JsonResponse) {
            return $prepared;
        }

        $user = $prepared['user'];
        $topic = $prepared['topic'];
        $subject = $prepared['subject'];
        $request = $prepared['request'];

        $coverUrl = $this->handleCoverUpload($request);

        $quiz = $this->createQuiz($user, $topic, $request, $coverUrl);

        $this->createQuestionsForQuiz($quiz, $user, $request);

        // If this is not a draft and not auto-approved, mark as approval requested
        if (!$quiz->is_draft && !$quiz->is_approved) {
            $quiz->approval_requested_at = now();
            $quiz->save();
        }

        return response()->json(['quiz' => $quiz], 201);
    }

    // Admin approves a quiz
    public function approve(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        // minimal role check: assume User model has is_admin flag
        if (!method_exists($user, 'isAdmin') && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $quiz->is_approved = true;
        $quiz->save();

        return response()->json(['quiz' => $quiz]);
    }
}
