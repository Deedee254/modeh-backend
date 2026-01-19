<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Http\Resources\LevelResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LevelController extends Controller
{
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

    // public list of levels with nested grades and subjects (for frontend grouping)
    // OPTIMIZED: Strategy B - Selective fields, Strategy A - Counts only
    public function index(Request $request)
    {
        $cacheKey = 'levels_index_' . md5(serialize($request->all()));

        $levels = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function() {
            // Strategy B: Select only essential fields
            return Level::select('id', 'name', 'slug', 'order', 'description')
                ->with(['grades' => function($q) {
                    $q->select('id', 'name', 'slug', 'level_id', 'description', 'type', 'display_name')
                      ->withCount('subjects')
                      ->with(['subjects' => function($s) {
                          // Strategy B: Only essential subject fields with quiz counts
                          $s->select('id', 'name', 'slug', 'grade_id', 'description', 'is_approved')
                            ->where('is_approved', true)
                            ->withCount('topics')
                            ->with(['topics' => function($t) { 
                                // Strategy A: Only counts, minimal topic data
                                $t->select('id', 'name', 'slug', 'subject_id')
                                  ->withCount('quizzes'); 
                            }]);
                      }]);
                }])
                ->orderBy('order')
                ->get();
        });

        return LevelResource::collection($levels);
    }

    // OPTIMIZED: Strategy D - Individual item cache, Strategy B - Selective fields
    public function show(Level $level)
    {
        $cacheKey = 'level_show_' . $level->id;

        $level = $this->safeCacheRemember($cacheKey, now()->addMinutes(10), function() use ($level) {
            // Strategy B: Load only essential fields
            $level->load(['grades' => function($q) {
                $q->select('id', 'name', 'slug', 'level_id', 'description', 'type', 'display_name')
                  ->withCount('subjects')
                  ->with(['subjects' => function($s) {
                      $s->select('id', 'name', 'slug', 'grade_id', 'description', 'is_approved')
                        ->where('is_approved', true)
                        ->withCount('topics')
                        ->with(['topics' => function($t) { 
                            $t->select('id', 'name', 'slug', 'subject_id')
                              ->withCount('quizzes'); 
                        }]);
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
