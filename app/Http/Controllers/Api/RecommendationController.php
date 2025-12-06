<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    /**
     * Return quizzes recommended for the current user (or grade).
     * Query params:
     * - per_page: int (default 5)
     * - for_grade: optional grade id/number to override user's grade
     */
    public function quizzes(Request $request)
    {
        $user = $request->user();
        $perPage = max(1, (int) $request->get('per_page', 5));

        // Determine grade (allow override via query param).
        $grade = $request->get('for_grade');
        if (!$grade && $user && $user->role === 'quizee') {
            // optimize: eager load profile if not responsible for N+1 issues in this context, 
            // but $request->user() is usually singular.
            // Access quizeeProfile relation (assuming loaded or loose read)
            $grade = $user->quizeeProfile->grade_id ?? null;
        }

        $query = Quiz::query()->with(['topic', 'topic.subject']);

        // Only recommend approved & published quizzes
        $query->where('is_approved', true)->where('visibility', 'published');

        if ($grade) {
            // Filter by direct grade_id on the quiz
            $query->where('grade_id', $grade);
        }

        // Randomize results to provide variety
        $query->inRandomOrder();

        $data = $query->paginate($perPage);

        return response()->json(['quizzes' => $data]);
    }
}
