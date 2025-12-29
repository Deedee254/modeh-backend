<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Models\Subject;
use App\Models\Quiz;
use App\Http\Resources\TopicResource;
use App\Http\Resources\QuizResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class TopicController extends Controller
{
    public function __construct()
    {
        // Protect most endpoints but allow public listing, show, and quizzes listing
        $this->middleware('auth:sanctum')->except(['index', 'show', 'quizzes']);
    }

    // List topics; optionally filter approved only via ?approved=1
    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = 'topics_index_' . md5(serialize($request->all()) . ($user ? $user->id : 'guest'));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function() use ($request, $user) {
            $query = Topic::query()->with(['subject.grade', 'representativeQuiz'])->withCount('quizzes');

            if ($q = $request->get('q')) {
                $query->where('name', 'like', "%{$q}%");
            }

            if (!is_null($request->get('approved'))) {
                $query->where('is_approved', (bool)$request->get('approved'));
            }

            if (!$user) {
                $query->where('is_approved', true);
            } else {
                if (empty($user->is_admin) || !$user->is_admin) {
                    $query->where(function ($q) use ($user) {
                        $q->where('is_approved', true)
                          ->orWhere('created_by', $user->id);
                    });
                }
            }

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

            $query->orderBy('name', 'asc');

            // Prefetch images to avoid N+1
            $collection = ($request->has('level_id') || $request->has('grade_id')) 
                ? $query->get() 
                : $query->paginate(max(1, (int)$request->get('per_page', 20)));

            $items = ($collection instanceof \Illuminate\Pagination\LengthAwarePaginator) 
                ? $collection->getCollection() 
                : $collection;

            $items->each(function($t) {
                if (empty($t->image) && $t->representativeQuiz) {
                    $t->quizzes_cover_image = Storage::url($t->representativeQuiz->cover_image);
                }
            });

            return $collection;
        });

        return TopicResource::collection($data);
    }

    // Show a single topic (public-safe view)
    public function show(Topic $topic)
    {
        $cacheKey = 'topic_show_' . $topic->id;

        $cachedData = Cache::remember($cacheKey, now()->addMinutes(10), function() use ($topic) {
            $topic->load(['subject.grade', 'representativeQuiz']);
            $topic->quizzes_count = Quiz::where('topic_id', $topic->id)->count();
            
            if (empty($topic->image) && $topic->representativeQuiz) {
                $topic->quizzes_cover_image = Storage::url($topic->representativeQuiz->cover_image);
            }
            return $topic->toArray();
        });

        return response()->json($cachedData);
    }

    // Get quizzes for a specific topic
    public function quizzes(Request $request, Topic $topic)
    {
        $user = $request->user();
        $cacheKey = 'topic_quizzes_' . $topic->id . '_' . md5(serialize($request->all()) . ($user ? $user->id : 'guest'));

        return Cache::remember($cacheKey, now()->addMinutes(10), function() use ($request, $topic, $user) {
            $query = Quiz::where('topic_id', $topic->id)
                        ->where('is_approved', true)
                        ->with(['topic', 'subject', 'grade', 'level', 'author'])
                        ->withCount(['attempts']);

            if ($user) {
                $query->withExists(['likes as liked' => function($q) use ($user) {
                    $q->where('user_id', $user->id);
                }]);
            }

            $perPage = min(100, max(1, (int)$request->get('per_page', 10)));
            $data = $query->paginate($perPage);

            return [
                'quizzes' => QuizResource::collection($data)->response()->getData(true)
            ];
        });
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
