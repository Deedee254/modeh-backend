<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\AchievementService;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class QuizController extends Controller
{
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

        // Normalize empty-string inputs (common from browser selects) to null for nullable numeric fields
        foreach (['subject_id','grade_id','timer_seconds','per_question_seconds','attempts_allowed','scheduled_at'] as $k) {
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

        // Log incoming payload for debugging (don't include file streams)
        \Log::info('QuizController@update incoming', array_merge($request->except(['cover', 'question_media']), ['files' => array_keys($request->files->all())]));

        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subject_id' => 'sometimes|nullable|exists:subjects,id',
            'grade_id' => 'sometimes|nullable|exists:grades,id',
            'level_id' => 'sometimes|nullable|exists:levels,id',
            'description' => 'sometimes|nullable|string',
            'youtube_url' => 'sometimes|nullable|url',
            'is_paid' => 'sometimes|boolean',
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

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Handle cover upload if present
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $path = \Illuminate\Support\Facades\Storage::disk('public')->putFile('covers', $file);
            $quiz->cover_image = \Illuminate\Support\Facades\Storage::url($path);
        }

        // Update known fields if present
        $fields = ['title','description','youtube_url','is_paid','timer_seconds','per_question_seconds','use_per_question_timer','attempts_allowed','shuffle_questions','shuffle_answers','visibility','scheduled_at','is_draft'];
        foreach ($fields as $f) {
            if ($request->has($f)) {
                $quiz->{$f} = $request->get($f);
            }
        }
        // allow updating taxonomy links — validate consistency if topic/subject/grade are changed
        if ($request->has('topic_id')) {
            $newTopic = \App\Models\Topic::find($request->get('topic_id'));
            if (!$newTopic) return response()->json(['message' => 'Topic not found'], 422);
            $quiz->topic_id = $newTopic->id;
            // if subject_id supplied, ensure it matches topic; otherwise set subject to topic's subject
            if ($request->has('subject_id')) {
                if ((string)$newTopic->subject_id !== (string)$request->get('subject_id')) {
                    return response()->json(['message' => 'Topic does not belong to the supplied subject'], 422);
                }
                $quiz->subject_id = $request->get('subject_id');
            } else {
                $quiz->subject_id = $newTopic->subject_id;
            }
        } else {
            if ($request->has('subject_id')) {
                $quiz->subject_id = $request->get('subject_id');
            }
        }

        if ($request->has('subject_id')) {
            $sub = \App\Models\Subject::find($request->get('subject_id'));
            if (!$sub) return response()->json(['message' => 'Subject not found'], 422);
            if ($request->has('grade_id')) {
                if ((string)$sub->grade_id !== (string)$request->get('grade_id')) {
                    return response()->json(['message' => 'Subject does not belong to the supplied grade'], 422);
                }
                $quiz->grade_id = $request->get('grade_id');
                // try to set level_id from grade if present
                try {
                    $g = \App\Models\Grade::find($request->get('grade_id'));
                    if ($g && isset($g->level_id)) $quiz->level_id = $g->level_id;
                } catch (\Throwable $_) {}
            } else {
                $quiz->grade_id = $sub->grade_id;
                try { $quiz->level_id = \App\Models\Grade::find($sub->grade_id)->level_id ?? null; } catch (\Throwable $_) {}
            }
        } else {
            if ($request->has('grade_id')) {
                // If grade provided without subject, only set it; subject remains unchanged
                $quiz->grade_id = $request->get('grade_id');
                try { $quiz->level_id = \App\Models\Grade::find($request->get('grade_id'))->level_id ?? null; } catch (\Throwable $_) {}
            }
        }

        // Ensure $topic is set for question-related logic below. If a new topic was supplied
        // earlier we used $newTopic; prefer that otherwise fall back to the quiz's current topic.
        $topic = null;
        if (isset($newTopic) && $newTopic) {
            $topic = $newTopic;
        } else {
            // $quiz->topic may be null if relation not loaded, try to load it
            $topic = $quiz->topic ?? \App\Models\Topic::find($quiz->topic_id);
        }

        // If questions included, reuse existing creation logic to attach them
        if ($request->filled('questions') && is_array($request->questions)) {
            // Support per-question file uploads: keys may be numeric index or question uid
            $mediaFiles = $request->file('question_media', []);
            foreach ($request->questions as $index => $q) {
                try {
                    $qType = $q['type'] ?? 'mcq';
                    $body = $q['text'] ?? ($q['body'] ?? '');
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

                    $siteSettings = \App\Models\SiteSetting::current();
                    $siteAutoQuestions = $siteSettings ? (bool)$siteSettings->auto_approve_questions : true;
                    // If topic/subject information is unavailable, default to site setting
                    $questionIsApproved = $siteAutoQuestions || (($topic && $topic->subject) ? (bool)($topic->subject->auto_approve ?? false) : false);

                    \App\Models\Question::create([
                        'quiz_id' => $quiz->id,
                        'created_by' => $user->id,
                        'type' => $qType,
                        'body' => $body,
                        'youtube_url' => $q['youtube_url'] ?? null,
                        'media_metadata' => $q['media_metadata'] ?? null,
                        'options' => $options,
                        'answers' => $answers,
                        'media_path' => $mediaPath,
                        'media_type' => $mediaType,
                        'difficulty' => $q['difficulty'] ?? 3,
                        'is_quiz-master_marked' => true,
                        'is_approved' => $questionIsApproved,
                        'is_banked' => isset($q['is_banked']) ? (bool)$q['is_banked'] : false,
                        'hint' => $q['hint'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    // ignore per-question failures for now
                }
            }
            try { $quiz->recalcDifficulty(); } catch (\Exception $e) {}
        }

        if ($request->has('level_id')) $quiz->level_id = $request->get('level_id');

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
        } catch (\Throwable $_) {}

        return response()->json($quiz);
    }

    // Paginated list for quizzes with search and filter support
    public function index(Request $request)
    {
        $user = $request->user();
    // Eager-load topic->subject so frontend can access subject data directly
    // and include a questions_count for each quiz using withCount
    $query = Quiz::query()->with(['topic.subject'])->withCount('questions');

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
        if (!is_null($request->get('approved'))) {
            $query->where('is_approved', (bool)$request->get('approved'));
        }

        $perPage = max(1, (int)$request->get('per_page', 10));
        $data = $query->paginate($perPage);
        return response()->json(['quizzes' => $data]);
    }

    // quiz-master creates a quiz under a topic (topic must be approved)
    public function store(Request $request)
    {
        $user = $request->user();

        // Normalize empty-string inputs (common from browser selects) to null for nullable numeric fields
        foreach (['subject_id','grade_id','timer_seconds','per_question_seconds','attempts_allowed','scheduled_at'] as $k) {
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

        // Log incoming payload for debugging (don't include file streams)
        \Log::info('QuizController@store incoming', array_merge($request->except(['cover', 'question_media']), ['files' => array_keys($request->files->all())]));

        $v = Validator::make($request->all(), [
            'topic_id' => 'required|exists:topics,id',
            'subject_id' => 'required|exists:subjects,id',
            'grade_id' => 'required|exists:grades,id',
            'level_id' => 'nullable|exists:levels,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'youtube_url' => 'nullable|url',
            'is_paid' => 'boolean',
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
            return response()->json(['errors' => $v->errors()], 422);
        }

        $topic = Topic::find($request->topic_id);
        if (!$topic || !$topic->is_approved) {
            return response()->json(['message' => 'Topic is not approved or does not exist'], 403);
        }

        // If subject_id not provided, infer from topic; otherwise ensure topic belongs to provided subject
        $providedSubjectId = $request->get('subject_id');
        if ($providedSubjectId) {
            if ((string)$topic->subject_id !== (string)$providedSubjectId) {
                return response()->json(['message' => 'Topic does not belong to the supplied subject'], 422);
            }
        } else {
            $request->merge(['subject_id' => $topic->subject_id]);
        }

        // If grade_id provided, ensure subject belongs to the grade; otherwise infer from subject
        $providedGradeId = $request->get('grade_id');
        $subject = \App\Models\Subject::find($request->get('subject_id'));
        if (!$subject) {
            return response()->json(['message' => 'Subject not found'], 422);
        }
        if ($providedGradeId) {
            if ((string)$subject->grade_id !== (string)$providedGradeId) {
                return response()->json(['message' => 'Subject does not belong to the supplied grade'], 422);
            }
        } else {
            $request->merge(['grade_id' => $subject->grade_id]);
        }

        $coverUrl = null;
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $path = Storage::disk('public')->putFile('covers', $file);
            $coverUrl = Storage::url($path);
        }

        $quiz = Quiz::create([
            'user_id' => $user->id,
            'topic_id' => $topic->id,
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
            'timer_seconds' => $request->timer_seconds ?? null,
            'per_question_seconds' => $request->get('per_question_seconds') ?? null,
            'use_per_question_timer' => (bool)$request->get('use_per_question_timer', false),
            'attempts_allowed' => $request->get('attempts_allowed') ?? null,
            'shuffle_questions' => (bool)$request->get('shuffle_questions', false),
            'shuffle_answers' => (bool)$request->get('shuffle_answers', false),
            'visibility' => $request->get('visibility', 'published'),
            'scheduled_at' => $request->get('scheduled_at') ? \Carbon\Carbon::parse($request->get('scheduled_at')) : null,
            'is_approved' => false,
            'is_draft' => $request->get('is_draft', false),
        ]);

        // If level_id wasn't supplied explicitly, try to infer from grade
        if (!$quiz->level_id && $quiz->grade_id) {
            try { $quiz->level_id = \App\Models\Grade::find($quiz->grade_id)->level_id ?? null; $quiz->save(); } catch (\Throwable $_) {}
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
        } catch (\Throwable $_) {}
        // If subject/topic auto-approve and settings allow, set approved
        if ($topic->subject->auto_approve) {
            $quiz->is_approved = true;
            $quiz->save();
        }

        // If questions were provided, create question rows attached to this quiz
        if ($request->filled('questions') && is_array($request->questions)) {
            // Support per-question file uploads: keys may be numeric index or question uid
            $mediaFiles = $request->file('question_media', []);
            foreach ($request->questions as $index => $q) {
                try {
                    $qType = $q['type'] ?? 'mcq';
                    $body = $q['text'] ?? ($q['body'] ?? '');
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

                    // if quiz_id is null (banked question), we mark is_banked true; here quiz exists so banked only if requested
                    $isBanked = isset($q['is_banked']) ? (bool)$q['is_banked'] : false;

                    \App\Models\Question::create([
                        'quiz_id' => $quiz->id,
                        'created_by' => $user->id,
                        'type' => $qType,
                        'body' => $body,
                        'youtube_url' => $q['youtube_url'] ?? null,
                        'media_metadata' => $q['media_metadata'] ?? null,
                        'options' => $options,
                        'answers' => $answers,
                        'media_path' => $mediaPath,
                        'media_type' => $mediaType,
                        'difficulty' => $q['difficulty'] ?? 3,
                        'is_quiz-master_marked' => true,
                        'is_approved' => false,
                        'is_banked' => $isBanked,
                        'hint' => $q['hint'] ?? null,
                    ]);
                } catch (\Exception $e) {
                    // ignore per-question failures for now
                }
            }
            // recalc difficulty
            try { $quiz->recalcDifficulty(); } catch (\Exception $e) {}
        }

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
