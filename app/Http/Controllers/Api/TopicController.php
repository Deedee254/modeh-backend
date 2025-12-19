<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\Quiz;

class TopicController extends Controller
{
    public function __construct()
    {
        // Protect most endpoints but allow public listing, show, and quizzes listing
        $this->middleware('auth:sanctum')->except(['index', 'show', 'quizzes']);
    }

    // quiz-master uploads an image for a topic
    public function uploadImage(Request $request, Topic $topic)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);
        if ($topic->created_by !== $user->id && empty($user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'image' => 'required|file|image|max:5120'
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $path = $request->file('image')->store('topics', 'public');
        $topic->image = $path;
        $topic->save();

        return response()->json(['topic' => $topic]);
    }

    // List topics; optionally filter approved only via ?approved=1
    public function index(Request $request)
    {
        $user = $request->user();
    // eager-load subject and the subject's grade so frontend can read nested grade info
    $query = Topic::query()->with(['subject.grade'])->withCount('quizzes');

        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        if (!is_null($request->get('approved'))) {
            $query->where('is_approved', (bool)$request->get('approved'));
        }

        // anonymous users see only approved topics.
        // Authenticated non-admin users should see approved topics plus any topics they created.
        if (!$user) {
            $query->where('is_approved', true);
        } else {
            if (empty($user->is_admin) || !$user->is_admin) {
                $query->where(function ($q) use ($user) {
                    $q->where('is_approved', true)
                      ->orWhere('created_by', $user->id);
                });
            }
            // admins see all topics (no additional where)
        }

        // Allow frontend to filter topics by grade_id or level_id via query params.
        // Example: /api/topics?grade_id=12  or /api/topics?level_id=3
        if ($gradeId = $request->get('grade_id')) {
            $query->whereHas('subject', function ($q) use ($gradeId) {
                $q->where('grade_id', $gradeId);
            });
        }

        if ($levelId = $request->get('level_id')) {
            $query->whereHas('subject', function ($q) use ($levelId) {
                $q->whereHas('grade', function ($g) use ($levelId) {
                    $g->where('level_id', $levelId);
                });
            });
        }

        $query->orderBy('created_at', 'desc');

        $transformer = function ($t) {
            $orig = $t->getAttribute('image') ?? null;
            $t->image = null;
            if (!empty($orig)) {
                try { $t->image = Storage::url($orig); } catch (\Exception $e) { $t->image = null; }
            }
            if (empty($t->image)) {
                $quiz = Quiz::where('topic_id', $t->id)->whereNotNull('cover_image')->orderBy('created_at', 'desc')->first();
                if ($quiz && $quiz->cover_image) {
                    try { $t->image = Storage::url($quiz->cover_image); } catch (\Exception $e) { $t->image = null; }
                }
            }
            // Attach grade name if subject->grade is available to make it easier for clients
            try {
                if (isset($t->subject) && isset($t->subject->grade) && $t->subject->grade) {
                    $t->grade = $t->subject->grade;
                    $t->grade_name = $t->subject->grade->name ?? ($t->subject->grade->display_name ?? null);
                } elseif (isset($t->grade_name) && !$t->grade_name) {
                    $t->grade_name = $t->grade_name ?? null;
                }
            } catch (\Exception $e) {
                // ignore
            }

            return $t;
        };

        // If filtering by level or grade, the frontend expects a full list.
        if ($request->has('level_id') || $request->has('grade_id')) {
            $topics = $query->get();
            $topics->transform($transformer);
            return response()->json($topics);
        }

        // Default behavior is to paginate
        $perPage = max(1, (int)$request->get('per_page', 10));
        $paginatedTopics = $query->paginate($perPage);
        $paginatedTopics->getCollection()->transform($transformer);

        return response()->json(['topics' => $paginatedTopics]);
    }

    // Show a single topic (public-safe view)
    public function show(Topic $topic)
    {
    // include subject and its grade for richer client-side rendering
    $topic->load('subject.grade');
        // Attach image url if present
        $orig = $topic->getAttribute('image') ?? null;
        $topic->image = null;
        if (!empty($orig)) {
            try { $topic->image = Storage::url($orig); } catch (\Exception $e) { $topic->image = null; }
        }
        // quiz count
        $topic->quizzes_count = Quiz::where('topic_id', $topic->id)->count();
        // attach grade name if available
        if ($topic->subject && $topic->subject->grade) {
            $topic->grade = $topic->subject->grade;
            $topic->grade_name = $topic->subject->grade->name ?? ($topic->subject->grade->display_name ?? null);
        }
        return response()->json(['topic' => $topic]);
    }

    // Get quizzes for a specific topic
    public function quizzes(Request $request, Topic $topic)
    {
        $query = Quiz::where('topic_id', $topic->id)
                    ->where('is_approved', true)
                    ->with(['topic', 'subject', 'grade', 'level', 'author'])
                    ->withCount(['attempts']);

        $perPage = min(100, max(1, (int)$request->get('per_page', 10)));
        $data = $query->paginate($perPage);

        // Attach storage URLs for any cover images and slugs
        $data->getCollection()->transform(function ($quiz) {
            if ($quiz->cover_image) {
                try {
                    $quiz->cover_image = Storage::url($quiz->cover_image);
                } catch (\Exception $e) {
                    $quiz->cover_image = null;
                }
            }
            
            // Add slugs for routing
            $quiz->grade_slug = $quiz->grade?->slug ?? null;
            $quiz->level_slug = $quiz->level?->slug ?? null;
            $quiz->topic_slug = $quiz->topic?->slug ?? null;
            $quiz->subject_slug = $quiz->topic?->subject?->slug ?? null;
            
            return $quiz;
        });

        return response()->json(['quizzes' => $data]);
    }

    // quiz-master creates a topic under a subject (subject must be approved)
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'subject_id' => 'required|exists:subjects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|file|image|max:5120'
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $subject = Subject::find($request->subject_id);
        if (!$subject->is_approved) {
            // If subject is not approved and auto_approve is false, block
            return response()->json(['message' => 'Subject is not approved'], 403);
        }

        $user = $request->user();

    // Determine whether to auto-approve based on subject.auto_approve, site runtime setting, or request hint
    $siteSettings = \App\Models\SiteSetting::current();
    $siteAuto = $siteSettings ? (bool)$siteSettings->auto_approve_topics : config('site.auto_approve_topics', true);
    $autoApprove = $subject->auto_approve || $siteAuto;

        $topic = Topic::create([
            'subject_id' => $subject->id,
            'created_by' => $user->id,
            'name' => $request->name,
            'description' => $request->description ?? null,
            'is_approved' => (bool)$autoApprove,
        ]);

        // Handle image upload if provided
        if ($request->hasFile('image')) {
            try {
                $path = $request->file('image')->store('topics', 'public');
                $topic->image = $path;
                $topic->save();
            } catch (\Exception $e) {
                // Image upload failed but topic was created, continue without image
                \Log::warning('Topic image upload failed: ' . $e->getMessage());
            }
        }

        // If not auto-approved but client requested immediate approval request, set approval_requested_at
        // client can send `request_approval=true` in creation payload to immediately request approval
        if (!$autoApprove && (bool)$request->get('request_approval')) {
            $topic->approval_requested_at = now();
            $topic->save();
        }

        return response()->json(['topic' => $topic], 201);
    }

    // Admin approves a topic
    public function approve(Request $request, Topic $topic)
    {
        $user = $request->user();
        if (!method_exists($user, 'isAdmin') && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $topic->is_approved = true;
        $topic->save();

        return response()->json(['topic' => $topic]);
    }
}
