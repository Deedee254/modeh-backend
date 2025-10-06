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
    $perPage = max(1, (int)$request->get('per_page', 5));

    // Determine grade (allow override via query param). Use optional() so anonymous users work.
    $grade = $request->get('for_grade') ?? optional($user)->grade;

        $query = Quiz::query()->with(['topic', 'topic.subject']);

        // Only recommend approved & published quizzes
        $query->where('is_approved', true)->where('visibility', 'published');

        if ($grade) {
            // Filter quizzes whose topic's subject matches the grade
            $query->whereHas('topic', function ($q) use ($grade) {
                $q->whereHas('subject', function ($s) use ($grade) {
                    $s->where('grade_id', $grade);
                });
            });
        }

        // Randomize results to provide variety
        $query->inRandomOrder();

        $data = $query->paginate($perPage);

        return response()->json(['quizzes' => $data]);
    }
}
