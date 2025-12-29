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

    // Return list of grades with subjects count (and optional search)
    public function index(Request $request)
    {
        $cacheKey = 'grades_index_' . md5(serialize($request->all()));

        $data = Cache::remember($cacheKey, now()->addMinutes(10), function() use ($request) {
            // Eager-load subjects and their topics (with quizzes count) so we can compute accurate quizzes_count per subject and grade
            $query = Grade::query()
                ->withCount('subjects')
                ->with(['level', 'subjects' => function($q) {
                    $q->where('is_approved', true)
                      ->withCount('topics')
                      ->with(['topics' => function($t) { $t->withCount('quizzes'); }]);
                }]);

            if ($q = $request->get('q')) {
                $query->where('name', 'like', "%{$q}%");
            }

            // Filter by level_id if provided (for cascading filters)
            if ($levelId = $request->get('level_id')) {
                $query->where('level_id', $levelId);
            }

            $grades = $query->orderBy('id')->get();
            
            // Compute counts to avoid N+1 in Resource
            $grades->each(function($grade) {
                $grade->subjects->each(function($sub) {
                    $sub->quizzes_count = $sub->topics->sum('quizzes_count');
                });
                $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
            });

            return $grades;
        });

        return GradeResource::collection($data);
    }

    // Show a single grade with subjects and counts
    public function show(Grade $grade)
    {
        $cacheKey = 'grade_show_' . $grade->id;

        $grade = Cache::remember($cacheKey, now()->addMinutes(10), function() use ($grade) {
            $grade->load(['level', 'subjects' => function($q) { 
                $q->where('is_approved', true)
                  ->withCount('topics')
                  ->with(['topics' => function($t) { $t->withCount('quizzes'); }]); 
            }]);
            
            $grade->subjects->each(function($sub) {
                $sub->quizzes_count = $sub->topics->sum('quizzes_count');
            });
            $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
            
            return $grade;
        });

        return new GradeResource($grade);
    }

    // Get topics for a specific grade (through its subjects)
    public function topics(Grade $grade)
    {
        $cacheKey = 'grade_topics_' . $grade->id;

        return Cache::remember($cacheKey, now()->addMinutes(10), function() use ($grade) {
            $topics = $grade->subjects()
                ->where('is_approved', true)
                ->with(['topics' => function($q) {
                    $q->withCount('quizzes')
                      ->with('representativeQuiz');
                }])
                ->get()
                ->pluck('topics')
                ->flatten()
                ->each(function($t) {
                    if (empty($t->image) && $t->representativeQuiz) {
                        $t->quizzes_cover_image = Storage::url($t->representativeQuiz->cover_image);
                    }
                })
                ->sortBy('name')
                ->values();

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
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $data = $request->only(['name', 'level_id', 'description', 'type', 'display_name']);
        if ($request->has('is_active')) $data['is_active'] = (bool)$request->get('is_active');

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
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

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