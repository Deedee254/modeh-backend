<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
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
    }

    // Paginated list for quizzes with search and filter support
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Quiz::query()->with('topic');

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

        $v = Validator::make($request->all(), [
            'topic_id' => 'required|exists:topics,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'youtube_url' => 'nullable|url',
            'is_paid' => 'boolean',
            'timer_seconds' => 'nullable|integer|min:1',
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
        if (!$topic->is_approved) {
            return response()->json(['message' => 'Topic is not approved'], 403);
        }

        $coverUrl = null;
        if ($request->hasFile('cover')) {
            $file = $request->file('cover');
            $path = Storage::disk('public')->putFile('covers', $file);
            $coverUrl = Storage::url($path);
        }

        $quiz = Quiz::create([
            'topic_id' => $topic->id,
            'created_by' => $user->id,
            'title' => $request->title,
            'description' => $request->description,
            'youtube_url' => $request->youtube_url,
            'cover_image' => $coverUrl,
            // if frontend sent 'access' === 'paywall' treat as paid
            'is_paid' => $request->get('access') === 'paywall' ? true : $request->get('is_paid', false),
            'timer_seconds' => $request->timer_seconds ?? null,
            'attempts_allowed' => $request->get('attempts_allowed') ?? null,
            'shuffle_questions' => (bool)$request->get('shuffle_questions', false),
            'shuffle_answers' => (bool)$request->get('shuffle_answers', false),
            'visibility' => $request->get('visibility', 'published'),
            'scheduled_at' => $request->get('scheduled_at') ? \Carbon\Carbon::parse($request->get('scheduled_at')) : null,
            'is_approved' => false,
            'is_draft' => $request->get('is_draft', false),
        ]);

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
                        'options' => $options,
                        'answers' => $answers,
                        'media_path' => $mediaPath,
                        'media_type' => $mediaType,
                        'difficulty' => $q['difficulty'] ?? 3,
                        'is_quiz-master_marked' => true,
                        'is_approved' => false,
                        'is_banked' => $isBanked,
                        'tags' => $q['tags'] ?? null,
                        'hint' => $q['hint'] ?? null,
                        'solution_steps' => $q['solution_steps'] ?? null,
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
