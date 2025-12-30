<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Quiz;
use App\Http\Resources\SubjectResource;
use App\Http\Resources\TopicResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class SubjectController extends Controller
{
    public function __construct()
    {
        // Remove auth for public browsing
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

    // List subjects with pagination, search and quizzes_count
    public function index(Request $request)
    {
        $cacheKey = 'subjects_index_' . md5(serialize($request->all()));

        $data = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($request) {
            // OPTIMIZED: Strategy B - Selective fields, Strategy A - Counts only
            // Load topic counts and quizzes per topic so we can compute accurate quizzes_count
            $query = Subject::query()
                ->select('id', 'name', 'slug', 'grade_id', 'description', 'icon', 'is_approved', 'auto_approve')
                ->where('is_approved', true)
                ->withCount('topics')
                ->with([
                    'grade:id,name,slug,level_id',
                    'representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.subject_id,quizzes.title',
                    'topics' => function ($q) {
                        // Strategy A: Only counts, minimal topic data
                        $q->select('id', 'name', 'slug', 'subject_id', 'is_approved')
                            ->where('is_approved', true)
                            ->withCount('quizzes');
                    }
                ]);

            if ($q = $request->get('q')) {
                $query->where('name', 'like', "%{$q}%");
            }

            // Filter by grade_id if provided
            if ($gradeId = $request->get('grade_id')) {
                $query->where('grade_id', $gradeId);
            }

            // Filter by level_id if provided
            if ($levelId = $request->get('level_id')) {
                $query->whereHas('grade', function ($q) use ($levelId) {
                    $q->where('level_id', $levelId);
                });
            }

            $query->orderBy('name', 'asc');
            // Strategy C: Limit pagination to prevent huge caches
            $perPage = min(50, max(1, (int) $request->get('per_page', 50)));

            $paginated = $query->paginate($perPage);

            // Compute counts and assign representative images
            $paginated->getCollection()->each(function ($s) {
                $s->quizzes_count = $s->topics->sum('quizzes_count');

                if (empty($s->image) && $s->representativeQuiz) {
                    $s->quizzes_cover_image = Storage::url($s->representativeQuiz->cover_image);
                }
            });

            return $paginated;
        });

        return SubjectResource::collection($data);
    }

    // Show a single subject with topics and representative image
    public function show(Subject $subject)
    {
        $cacheKey = 'subject_show_' . $subject->id;

        $subject = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($subject) {
            // OPTIMIZED: Strategy B - Selective fields, Strategy D - Individual item cache
            $subject->load([
                'grade:id,name,slug,level_id',
                'representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.subject_id,quizzes.title',
                'topics' => function ($q) {
                    $q->select('id', 'name', 'slug', 'subject_id', 'is_approved', 'description', 'image')
                        ->where('is_approved', true)
                        ->withCount('quizzes');
                }
            ]);

            $subject->quizzes_count = $subject->topics->sum('quizzes_count');

            if (empty($subject->image) && $subject->representativeQuiz) {
                $subject->quizzes_cover_image = Storage::url($subject->representativeQuiz->cover_image);
            }

            return $subject;
        });

        return new SubjectResource($subject);
    }

    // quiz-master proposes a subject under a grade
    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'grade_id' => 'required|exists:grades,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'auto_approve' => 'boolean',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = $request->user();

        $subject = Subject::create([
            'grade_id' => $request->grade_id,
            'created_by' => $user->id,
            'name' => $request->name,
            'description' => $request->description ?? null,
            'auto_approve' => $request->get('auto_approve', false),
            'is_approved' => false,
        ]);

        // If admin/global auto-approval exists, this could be applied here.

        return response()->json(['subject' => $subject], 201);
    }

    // Admin approves a subject
    public function approve(Request $request, Subject $subject)
    {
        $user = $request->user();
        if (!method_exists($user, 'isAdmin') && !$user->is_admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $subject->is_approved = true;
        $subject->save();

        return response()->json(['subject' => $subject]);
    }

    // quiz-master/admin can upload an icon for a subject
    public function uploadIcon(Request $request, Subject $subject)
    {
        $user = $request->user();
        if (!$user)
            return response()->json(['message' => 'Unauthorized'], 401);
        // Use getAttribute to avoid PHP notices if the attribute is missing
        $subjectOwner = $subject->getAttribute('created_by');
        if ((string) ($subjectOwner ?? '') !== (string) ($user->id ?? '') && empty($user->is_admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $v = Validator::make($request->all(), [
            'icon' => 'required|file|image|max:2048'
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $path = $request->file('icon')->store('subjects/icons', 'public');
        $subject->icon = $path;
        $subject->save();

        return response()->json(['subject' => $subject]);
    }

    // Get topics for a specific subject
    public function topics(Request $request, Subject $subject)
    {
        $cacheKey = 'subject_topics_' . $subject->id . '_' . md5(serialize($request->all()));

        return $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($request, $subject) {
            // OPTIMIZED: Strategy B - Selective fields, Strategy C - Pagination limits
            $query = $subject->topics()
                ->select('id', 'name', 'slug', 'subject_id', 'description', 'image', 'is_approved')
                ->where('is_approved', true)
                ->withCount('quizzes')
                ->with(['representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.topic_id,quizzes.title']);

            if ($request->has('approved')) {
                $query->where('is_approved', (bool) $request->get('approved'));
            }

            // Strategy C: Limit pagination
            $perPage = min(50, max(1, (int) $request->get('per_page', 10)));
            $data = $query->paginate($perPage);

            // Populate quizzes_cover_image for Resource
            $data->getCollection()->each(function ($t) {
                if (empty($t->image) && $t->representativeQuiz) {
                    $t->quizzes_cover_image = Storage::url($t->representativeQuiz->cover_image);
                }
            });

            return [
                'topics' => TopicResource::collection($data)->response()->getData(true)
            ];
        });
    }
}
