<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    /**
     * Public leaderboard endpoint.
     *
     * Query params supported:
     *  - timeframe: all-time (default), daily, weekly, monthly (currently treated as all-time)
     *  - level_id: filter by level (optional)
     *  - grade_id: filter by grade (optional)
     *  - subject_id: filter by subject (optional)
     *  - topic_id: filter by topic (optional)
     *  - quiz_id: filter by quiz (optional)
     *  - page: pagination page
     *  - per_page: items per page (default 50)
     *  - sort_by: points|name|created_at (default points)
     *  - sort_dir: asc|desc (default desc)
     *  - q: search string (matches name or email)
     */
    public function index(Request $request)
    {
        $timeframe = $request->get('timeframe', 'all-time'); // all-time, daily, weekly, monthly
        $levelId = $request->get('level_id');
        $gradeId = $request->get('grade_id');
        $subjectId = $request->get('subject_id');
        $topicId = $request->get('topic_id');
        $quizId = $request->get('quiz_id');
        $page = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 50);
        $sortBy = $request->get('sort_by', 'points');
        $sortDir = strtolower($request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $q = $request->get('q');

        // Resolve start date based on timeframe
        $startDate = null;
        if ($timeframe === 'daily') {
            $startDate = now()->startOfDay();
        } elseif ($timeframe === 'weekly') {
            $startDate = now()->startOfWeek();
        } elseif ($timeframe === 'monthly') {
            $startDate = now()->startOfMonth();
        }

        // Helper to apply common constraints to relationships (timeframe, context)
        $applyConstraints = function($sub) use ($startDate, $quizId, $topicId, $subjectId) {
            if ($startDate) {
                $sub->where('created_at', '>=', $startDate);
            }
            if ($quizId) {
                $sub->where('quiz_id', $quizId);
            }
            if ($topicId || $subjectId) {
                $sub->whereHas('quiz', function($q) use ($topicId, $subjectId) {
                    if ($topicId) $q->where('topic_id', $topicId);
                    if ($subjectId) $q->where('subject_id', $subjectId);
                });
            }
        };

        $query = User::query()->where('role', 'quizee');

        if ($quizId) {
            // If quiz_id is provided, we return the best score per user for this specific quiz.
            // We join with subqueries to find each user's max score and their best time for that score.
            $bestScoresSub = \DB::table('quiz_attempts')
                ->select('user_id', \DB::raw('MAX(score) as max_score'))
                ->where('quiz_id', $quizId)
                ->whereNotNull('score');
            
            if ($startDate) {
                $bestScoresSub->where('created_at', '>=', $startDate);
            }
            
            $bestScoresSub->groupBy('user_id');

            $bestTimesSub = \DB::table('quiz_attempts')
                ->select('user_id', 'score', \DB::raw('MIN(total_time_seconds) as min_time'))
                ->where('quiz_id', $quizId)
                ->whereNotNull('score');
            
            if ($startDate) {
                $bestTimesSub->where('created_at', '>=', $startDate);
            }
            
            $bestTimesSub->groupBy('user_id', 'score');

            $query->joinSub($bestScoresSub, 'best_scores', 'users.id', '=', 'best_scores.user_id')
                  ->joinSub($bestTimesSub, 'best_times', function ($join) {
                      $join->on('users.id', '=', 'best_times.user_id')
                           ->on('best_scores.max_score', '=', 'best_times.score');
                  })
                  ->select(['users.*'])
                  ->selectRaw('best_scores.max_score as points')
                  ->selectRaw('best_times.min_time as best_time');
            
            // Override sort_by for quiz leaderboard
            $sortBy = 'points';
        } else {
            // Global/Contextual points leaderboard
            if ($startDate || $topicId || $subjectId) {
                // aggregate points from attempts in the period/context
                $query->withSum(['quizAttempts as timeframe_points' => $applyConstraints], 'points_earned');
                
                $query->select(['id', 'name', 'email', 'social_avatar', 'avatar_url', 'created_at', 'role']);
                $query->selectRaw('COALESCE(timeframe_points, 0) as points');
            } else {
                // All-time global leaderboard: use cached users.points column
                $hasPointsColumn = \Schema::hasColumn('users', 'points');
                if ($hasPointsColumn) {
                    $query->select(['id', 'name', 'email', 'points', 'social_avatar', 'avatar_url', 'created_at', 'role']);
                } else {
                    $query->selectRaw('id, name, email, 0 as points, social_avatar, avatar_url, created_at, role');
                }
            }
        }

        // Add metrics: average score and attempts count
        $query->withAvg(['quizAttempts as average_score' => $applyConstraints], 'score');
        $query->withCount(['quizAttempts as attempts_count' => $applyConstraints]);

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // Filter by level if provided
        if ($levelId) {
            $query->whereHas('quizeeProfile', function ($sub) use ($levelId) {
                $sub->where('level_id', $levelId);
            });
        }

        // Filter by grade if provided
        if ($gradeId) {
            $query->whereHas('quizeeProfile', function ($sub) use ($gradeId) {
                $sub->where('grade_id', $gradeId);
            });
        }

        // Filter by topic if provided
        if ($topicId && !$quizId) {
            $query->whereHas('quizAttempts', $applyConstraints);
        }

        // Filter by subject if provided
        if ($subjectId && !$quizId) {
            $query->whereHas('quizAttempts', $applyConstraints);
        }

        // Filter by quiz if provided (if we didn't already join above)
        if ($quizId && !str_contains($query->toSql(), 'join')) {
            $query->whereHas('quizAttempts', $applyConstraints);
        }

        // Validate sort_by allowed values
        $allowedSort = ['points', 'name', 'created_at', 'average_score'];
        if (!in_array($sortBy, $allowedSort)) {
            $sortBy = 'points';
        }

        // Sorting logic
        if ($sortBy === 'points') {
            if ($quizId) {
                // For specific quiz: Highest score first, then fastest time (minimum seconds)
                $query->orderByRaw("points {$sortDir}")
                      ->orderByRaw("CASE WHEN best_time IS NULL THEN 2147483647 ELSE best_time END ASC")
                      ->orderBy('name', 'asc');
            } else {
                $query->orderByRaw("COALESCE(points, 0) {$sortDir}")
                      ->orderBy('name', 'asc');
            }
        } elseif ($sortBy === 'average_score') {
            // When sorting by average score, prioritize those with more attempts if scores are tied
            // Also filter out users with 0 attempts to avoid a leaderboard full of 0s
            $query->whereHas('quizAttempts')
                  ->orderByRaw("average_score {$sortDir}")
                  ->orderBy('attempts_count', 'desc');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        try {
            $paginated = $query->with('institutions')->paginate($perPage, ['*'], 'page', $page);

            // Normalize collection items to a stable shape expected by frontend
            $paginated->getCollection()->transform(function ($u) use ($quizId) {
                return [
                    'id' => $u->id,
                    'name' => $u->name ?? ($u->email ?? 'Unknown'),
                    'avatar' => $u->avatar,
                    'points' => $quizId ? (float)($u->points ?? 0) : (int)($u->points ?? 0),
                    'average_score' => $u->average_score ? round((float)$u->average_score, 1) : 0,
                    'attempts_count' => (int)($u->attempts_count ?? 0),
                    'total_time_seconds' => isset($u->best_time) ? (int)$u->best_time : null,
                    'country' => $u->country ?? null,
                    'institution_name' => $u->institutions?->first()?->name ?? null,
                ];
            });

            return response()->json($paginated);
        } catch (\Exception $e) {
            // Log full exception with stack so debugging is easier
            \Log::error('Leaderboard query failed: ' . $e->getMessage(), ['exception' => $e]);

            // Return empty result set rather than 500 to avoid breaking public pages
            return response()->json([
                'data' => [],
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => 1
            ]);
        }
    }
}
