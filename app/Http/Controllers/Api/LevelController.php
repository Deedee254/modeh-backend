<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Http\Resources\LevelResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LevelController extends Controller
{
    // public list of levels with nested grades and subjects (for frontend grouping)
    public function index(Request $request)
    {
        $cacheKey = 'levels_index_' . md5(serialize($request->all()));

        $levels = Cache::remember($cacheKey, now()->addMinutes(10), function() {
            return Level::with(['grades' => function($q) {
                $q->withCount('subjects')
                  ->with(['subjects' => function($s) {
                      $s->where('is_approved', true)
                        ->withCount('topics')
                        ->with(['topics' => function($t) { $t->withCount('quizzes'); }]);
                  }]);
            }])->orderBy('order')->get();
        });

        return LevelResource::collection($levels);
    }

    public function show(Level $level)
    {
        $cacheKey = 'level_show_' . $level->id;

        $level = Cache::remember($cacheKey, now()->addMinutes(10), function() use ($level) {
            $level->load(['grades' => function($q) {
                $q->withCount('subjects')
                  ->with(['subjects' => function($s) {
                      $s->where('is_approved', true)
                        ->withCount('topics')
                        ->with(['topics' => function($t) { $t->withCount('quizzes'); }]);
                  }]);
            }]);
            return $level;
        });

        return new LevelResource($level);
    }

    // create (requires auth/admin; keep simple and rely on middleware in routes)
    public function store(Request $request)
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'order' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $data = $request->only(['name', 'slug', 'order', 'description']);
        if (empty($data['slug'])) {
            $data['slug'] = \Str::slug($data['name']);
        }
        $level = Level::create($data);
        return response()->json(['level' => $level], 201);
    }

    public function update(Request $request, Level $level)
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255',
            'order' => 'sometimes|nullable|integer',
            'description' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $level->update($request->only(['name', 'slug', 'order', 'description']));
        return response()->json(['level' => $level]);
    }

    public function destroy(Level $level)
    {
        $level->delete();
        return response()->json(['deleted' => true]);
    }
}
