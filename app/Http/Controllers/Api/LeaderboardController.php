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

        // Resolve quizId if it's a slug
        if ($quizId && !is_numeric($quizId)) {
            $quizId = \App\Models\Quiz::where('slug', $quizId)->value('id');
        }

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
            // Quiz-specific leaderboard: Calculate best score, average, and counts in one pass
            $statsSub = \DB::table('quiz_attempts')
                ->select('user_id')
                ->selectRaw('MAX(score) as points')
                ->selectRaw('AVG(score) as average_score')
                ->selectRaw('COUNT(*) as attempts_count')
                ->where('quiz_id', $quizId)
                ->whereNotNull('score')
                ->groupBy('user_id');
            
            if ($startDate) {
                $statsSub->where('created_at', '>=', $startDate);
            }

            // Find best time for the best score
            $bestTimesSub = \DB::table('quiz_attempts')
                ->select('user_id', 'score', \DB::raw('MIN(total_time_seconds) as min_time'))
                ->where('quiz_id', $quizId)
                ->whereNotNull('score');
            
            if ($startDate) {
                $bestTimesSub->where('created_at', '>=', $startDate);
            }
            
            $bestTimesSub->groupBy('user_id', 'score');

            $query->joinSub($statsSub, 'stats', 'users.id', '=', 'stats.user_id')
                  ->joinSub($bestTimesSub, 'best_times', function ($join) {
                      $join->on('users.id', '=', 'best_times.user_id')
                           ->on('stats.points', '=', 'best_times.score');
                  })
                  ->select([
                      'users.id',
                      'users.name',
                      'users.email',
                      'users.social_avatar',
                      'users.avatar_url',
                      'users.created_at',
                      'users.role',
                      'stats.points',
                      'stats.average_score',
                      'stats.attempts_count',
                      'best_times.min_time as best_time'
                  ]);
            
            // For quiz leaderboard, we don't need additional withAvg/withCount as they are in the join
        } else {
            // Global/Contextual points leaderboard
            if ($startDate || $topicId || $subjectId) {
                $query->withSum(['quizAttempts as timeframe_points' => $applyConstraints], 'points_earned');
                $query->select(['id', 'name', 'email', 'social_avatar', 'avatar_url', 'created_at', 'role']);
                $query->selectRaw('COALESCE(timeframe_points, 0) as points');
            } else {
                $hasPointsColumn = \Schema::hasColumn('users', 'points');
                if ($hasPointsColumn) {
                    $query->select(['id', 'name', 'email', 'points', 'social_avatar', 'avatar_url', 'created_at', 'role']);
                } else {
                    $query->selectRaw('id, name, email, 0 as points, social_avatar, avatar_url, created_at, role');
                }
            }
            
            // Attach metrics for non-quiz-specific view
            $query->withAvg(['quizAttempts as average_score' => $applyConstraints], 'score');
            $query->withCount(['quizAttempts as attempts_count' => $applyConstraints]);
        }

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        // Filter by level/grade
        if ($levelId) {
            $query->whereHas('quizeeProfile', fn($sub) => $sub->where('level_id', $levelId));
        }
        if ($gradeId) {
            $query->whereHas('quizeeProfile', fn($sub) => $sub->where('grade_id', $gradeId));
        }

        // Contextual filters (only if quizId is not already filtering via join)
        if (!$quizId) {
            if ($topicId) $query->whereHas('quizAttempts', $applyConstraints);
            if ($subjectId) $query->whereHas('quizAttempts', $applyConstraints);
        }

        // Validate sort_by
        $allowedSort = ['points', 'name', 'created_at', 'average_score'];
        if (!in_array($sortBy, $allowedSort)) $sortBy = 'points';

        // Sorting
        if ($sortBy === 'points') {
            $query->orderByRaw("points {$sortDir}")
                  ->orderByRaw("CASE WHEN " . ($quizId ? 'best_time' : '0') . " IS NULL THEN 2147483647 ELSE " . ($quizId ? 'best_time' : '0') . " END ASC")
                  ->orderBy('name', 'asc');
        } elseif ($sortBy === 'average_score') {
            $query->whereHas('quizAttempts', $applyConstraints)
                  ->orderByRaw("average_score {$sortDir}")
                  ->orderBy('attempts_count', 'desc');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        try {
            $paginated = $query->with('institutions')->paginate($perPage, ['*'], 'page', $page);

            $paginated->getCollection()->transform(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name ?? ($u->email ?? 'Unknown'),
                    'avatar' => $u->avatar,
                    'points' => (float)($u->points ?? 0),
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
