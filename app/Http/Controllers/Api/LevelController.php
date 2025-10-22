<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Level;
use Illuminate\Http\Request;

class LevelController extends Controller
{
    // public list of levels with nested grades and subjects (for frontend grouping)
    public function index(Request $request)
    {
        $levels = Level::with(['grades.subjects'])->orderBy('order')->get();
        return response()->json(['levels' => $levels]);
    }

    public function show(Level $level)
    {
        $level->load('grades.subjects');
        return response()->json(['level' => $level]);
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
