<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function __construct()
    {
        // Public for browsing
    }

    // Return list of grades with subjects count (and optional search)
    public function index(Request $request)
    {
        \Log::info('Accessing grades index');
        
        $query = Grade::query()
            ->withCount('subjects')
            ->with(['subjects' => function($q) {
                $q->where('is_approved', true)->withCount('topics as quizzes_count');
            }]);
        $query->with('level');

        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        // Filter by level_id if provided (for cascading filters)
        if ($levelId = $request->get('level_id')) {
            $query->where('level_id', $levelId);
        }

        $grades = $query->orderBy('id')->get();
        // Add total quizzes per grade
        $grades->each(function($grade) {
            $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
        });
        return response()->json(['grades' => $grades]);
    }

    // Show a single grade with subjects and counts
    public function show(Grade $grade)
    {
        $grade->load(['subjects' => function($q) { $q->where('is_approved', true)->withCount('topics as quizzes_count'); }]);
    $grade->load('level');
        $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
        return response()->json(['grade' => $grade]);
    }

    // Get topics for a specific grade (through its subjects)
    public function topics(Grade $grade)
    {
        $topics = $grade->subjects()
            ->where('is_approved', true)
            ->with(['topics' => function($q) {
                $q->withCount('quizzes');
            }])
            ->get()
            ->pluck('topics')
            ->flatten()
            ->sortBy('name')
            ->values();

        return response()->json(['topics' => $topics]);
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