<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Http\Resources\GradeResource;
use App\Http\Resources\TopicResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class GradeController extends Controller
{
    public function __construct()
    {
        // Public for browsing
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

    // Return list of grades with subjects count (and optional search)
    // OPTIMIZED: Uses selective fields, pagination limits, and efficient caching
    public function index(Request $request)
    {
        $cacheKey = 'grades_index_' . md5(serialize($request->all()));

        $data = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($request) {
            // Strategy B: Select only essential fields to reduce cache size
            $query = Grade::query()
                ->select('id', 'name', 'slug', 'level_id', 'description', 'type', 'display_name', 'is_active')
                ->withCount('subjects')
                ->with(['level:id,name,slug']) // Only load level essentials
                ->with([
                    'subjects' => function ($q) {
                        // Strategy B: Only select needed subject fields
                        $q->select('id', 'name', 'slug', 'grade_id', 'description', 'is_approved')
                            ->where('is_approved', true)
                            ->withCount('topics')
                            ->with([
                                'topics' => function ($t) {
                            // Strategy A: Only cache counts, not full topic data
                            $t->select('id', 'name', 'slug', 'subject_id')
                                ->withCount('quizzes');
                        }
                            ]);
                    }
                ]);

            if ($q = $request->get('q')) {
                $query->where('name', 'like', "%{$q}%");
            }

            // Filter by level_id if provided (for cascading filters)
            if ($levelId = $request->get('level_id')) {
                $query->where('level_id', $levelId);
            }

            $grades = $query->orderBy('id')->get();

            // Compute counts to avoid N+1 in Resource
            $grades->each(function ($grade) {
                $grade->subjects->each(function ($sub) {
                    $sub->quizzes_count = $sub->topics->sum('quizzes_count');
                });
                $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
            });

            return $grades;
        });

        return GradeResource::collection($data);
    }

    // Show a single grade with subjects and counts
    // OPTIMIZED: Strategy D - Cache individual items with selective fields
    public function show(Grade $grade)
    {
        $cacheKey = 'grade_show_' . $grade->id;

        $grade = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($grade) {
            // Strategy B: Load only essential fields
            $grade->load([
                'level:id,name,slug',
                'subjects' => function ($q) {
                    $q->select('id', 'name', 'slug', 'grade_id', 'description', 'is_approved')
                        ->where('is_approved', true)
                        ->withCount('topics')
                        ->with([
                            'topics' => function ($t) {
                                // Strategy A: Only counts, minimal topic data
                                $t->select('id', 'name', 'slug', 'subject_id')
                                    ->withCount('quizzes');
                            }
                        ]);
                }
            ]);

            $grade->subjects->each(function ($sub) {
                $sub->quizzes_count = $sub->topics->sum('quizzes_count');
            });
            $grade->quizzes_count = $grade->subjects->sum('quizzes_count');

            return $grade;
        });

        return new GradeResource($grade);
    }

    // Get topics for a specific grade (through its subjects)
    // OPTIMIZED: Strategy C - Pagination limits, Strategy B - Selective fields
    public function topics(Grade $grade)
    {
        $perPage = min(100, max(1, (int) request()->get('per_page', 50))); // Strategy C: Limit max items
        $cacheKey = 'grade_topics_' . $grade->id . '_' . $perPage;

        return $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function () use ($grade, $perPage) {
            // Strategy B: Select only needed fields
            $topics = $grade->subjects()
                ->select('id', 'name', 'grade_id')
                ->where('is_approved', true)
                ->with([
                    'topics' => function ($q) {
                        $q->select('id', 'name', 'slug', 'subject_id', 'image', 'description')
                            ->withCount('quizzes')
                            ->with(['representativeQuiz:quizzes.id,quizzes.cover_image,quizzes.topic_id']); // Only needed quiz fields
                    }
                ])
                ->get()
                ->pluck('topics')
                ->flatten()
                ->each(function ($t) {
                    if (empty($t->image) && $t->representativeQuiz) {
                        $t->quizzes_cover_image = Storage::url($t->representativeQuiz->cover_image);
                    }
                })
                ->sortBy('name')
                ->values();

            // Strategy C: Apply pagination limit
            if ($topics->count() > $perPage) {
                $topics = $topics->take($perPage);
            }

            return [
                'topics' => TopicResource::collection($topics)
            ];
        });
    }

    // Create a grade (requires authenticated user - routes should protect this)
    public function store(Request $request)
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'level_id' => 'nullable|exists:levels,id',
            'description' => 'nullable|string',
            'type' => 'nullable|string|max:50',
            'display_name' => 'nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $data = $request->only(['name', 'level_id', 'description', 'type', 'display_name']);
        if ($request->has('is_active'))
            $data['is_active'] = (bool) $request->get('is_active');

        $grade = Grade::create($data);

        return response()->json(['grade' => $grade], 201);
    }

    // Update a grade
    public function update(Request $request, Grade $grade)
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'level_id' => 'sometimes|nullable|exists:levels,id',
            'description' => 'sometimes|nullable|string',
            'type' => 'sometimes|nullable|string|max:50',
            'display_name' => 'sometimes|nullable|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails())
            return response()->json(['errors' => $v->errors()], 422);

        $grade->update($request->only(['name', 'level_id', 'description', 'type', 'display_name', 'is_active']));
        return response()->json(['grade' => $grade]);
    }

    // Delete a grade
    public function destroy(Grade $grade)
    {
        $grade->delete();
        return response()->json(['deleted' => true]);
    }
}