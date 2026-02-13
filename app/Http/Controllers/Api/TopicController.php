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

    /**
     * Safely cache data with fallback if caching fails (e.g., due to size limits)
     */
    private function safeCacheRemember(string $key, $ttl, callable $callback)
    {
        try {
            // Try to get from cache first
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            // Generate the data
            $data = $callback();

            // Try to store in cache, but don't fail if it's too large
            try {
                Cache::put($key, $data, $ttl);
            } catch (\Exception $e) {
                // Log the error but continue without caching
                \Log::warning('Failed to cache data', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                    'error_type' => get_class($e)
                ]);
            }

            return $data;
        } catch (\Exception $e) {
            // If cache retrieval fails, just execute the callback
            \Log::warning('Cache operation failed, falling back to direct query', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }

    // List topics; optionally filter approved only via ?approved=1
    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = 'topics_index_' . md5(serialize($request->all()) . ($user ? $user->id : 'guest'));

        $data = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($request, $user) {
            // OPTIMIZED: Strategy B - Selective fields
            $query = Topic::query()
                ->select('id', 'name', 'slug', 'subject_id', 'description', 'image', 'is_approved', 'created_by')
                ->with([
                    'subject:id,name,slug,grade_id',
                    'subject.grade:id,name,slug,level_id',
                    'representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.topic_id,quizzes.title'
                ])
                ->withCount('quizzes');

            if ($q = $request->get('q')) {
                $query->where('name', 'like', "%{$q}%");
            }

            if (!is_null($request->get('approved'))) {
                $query->where('is_approved', (bool) $request->get('approved'));
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

            // If client requests topics from the quiz-master's followed subjects only
            // e.g. ?followed=1
            if ($request->get('followed')) {
                try {
                    if ($request->user() && $request->user()->quizMasterProfile) {
                        $profile = $request->user()->quizMasterProfile;
                        $subjectIds = $profile->subjects ?? [];
                        if (is_array($subjectIds) && count($subjectIds) > 0) {
                            $query->whereIn('subject_id', $subjectIds);
                        }
                    }
                } catch (\Throwable $e) {
                    // If anything goes wrong while resolving profile subjects, ignore and continue without filtering
                    \Log::warning('Failed to apply followed-subjects filter', ['error' => $e->getMessage()]);
                }
            }

            $query->orderBy('name', 'asc');

            // Strategy C: Limit pagination to prevent huge caches
            $perPage = min(50, max(1, (int) $request->get('per_page', 20)));

            // Prefetch images to avoid N+1
            $collection = ($request->has('level_id') || $request->has('grade_id'))
                ? $query->get()
                : $query->paginate($perPage);

            $items = ($collection instanceof \Illuminate\Pagination\LengthAwarePaginator)
                ? $collection->getCollection()
                : $collection;

            $items->each(function ($t) {
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

        $cachedData = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($topic) {
            // OPTIMIZED: Strategy B - Selective fields, Strategy D - Individual item cache
            $topic->load([
                'subject:id,name,slug,grade_id',
                'subject.grade:id,name,slug,level_id',
                'representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.topic_id,quizzes.title'
            ]);
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

        return $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($request, $topic, $user) {
            // OPTIMIZED: Strategy B - Selective fields, Strategy C - Pagination limits
            $query = Quiz::where('topic_id', $topic->id)
                ->where('is_approved', true)
                ->select([
                    'id',
                    'title',
                    'description',
                    'cover_image',
                    'youtube_url',
                    'topic_id',
                    'subject_id',
                    'grade_id',
                    'level_id',
                    'is_paid',
                    'timer_seconds',
                    'difficulty',
                    'visibility',
                    'created_by',
                    'created_at',
                    'updated_at'
                ])
                ->with([
                    'topic:id,name,slug',
                    'subject:id,name,slug',
                    'grade:id,name,slug',
                    'level:id,name,slug',
                    'author:id,name,email'
                ])
                ->withCount(['attempts']);

            if ($user) {
                $query->withExists([
                    'likes as liked' => function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    }
                ]);
            }

            // Strategy C: Limit pagination
            $perPage = min(50, max(1, (int) $request->get('per_page', 10)));
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
        $siteAuto = $siteSettings ? (bool) $siteSettings->auto_approve_topics : config('site.auto_approve_topics', true);
        $autoApprove = $subject->auto_approve || $siteAuto;

        $topic = Topic::create([
            'subject_id' => $subject->id,
            'created_by' => $user->id,
            'name' => $request->name,
            'description' => $request->description ?? null,
            'is_approved' => (bool) $autoApprove,
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
        if (!$autoApprove && (bool) $request->get('request_approval')) {
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
