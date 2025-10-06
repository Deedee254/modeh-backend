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
        $query = Grade::query()
            ->withCount('subjects')
            ->with(['subjects' => function($q) {
                $q->where('is_approved', true)->withCount('topics as quizzes_count');
            }]);

        if ($q = $request->get('q')) {
            $query->where('name', 'like', "%{$q}%");
        }

        $grades = $query->orderBy('id')->get();
        // Add total quizzes per grade
        $grades->each(function($grade) {
            $grade->quizzes_count = $grade->subjects->sum('quizzes_count');
        });
        return response()->json(['grades' => $grades]);
    }
}