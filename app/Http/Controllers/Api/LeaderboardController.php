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
        $timeframe = $request->get('timeframe', 'all-time');
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

        // NOTE: For now we use the users.points column as the authoritative "points" value
        // (this corresponds to how AchievementService increments user points). Timeframe
        // filters (daily/weekly/monthly) are not yet implemented and will return all-time
        // results. This is a deliberate minimal implementation to match frontend needs;
        // future work: compute timeframe-limited points by aggregating attempts/achievements.

        // Check if points column exists and build query accordingly
        $hasPointsColumn = \Schema::hasColumn('users', 'points');
        
        if ($hasPointsColumn) {
            try {
                // Avoid selecting optional columns such as `country` which may not exist
                // across all environments. Select a minimal safe set and normalize later.
                $query = User::query()->select(['id', 'name', 'email', 'points', 'social_avatar', 'avatar_url', 'created_at']);
            } catch (\Exception $e) {
                \Log::warning('Failed to query with points column: ' . $e->getMessage());
                $query = User::query()->selectRaw('id, name, email, 0 as points, social_avatar, created_at');
            }
        } else {
            // Fallback for older schema without points column
            $query = User::query()->selectRaw('id, name, email, 0 as points, social_avatar, created_at');
            \Log::info('Using fallback query - points column does not exist');
        }

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

        // Filter by subject if provided
        // Note: subjects are stored as JSON array in the quizee profile, so we use whereHas with whereJsonContains
        if ($subjectId) {
            $query->whereHas('quizeeProfile', function ($sub) use ($subjectId) {
                $sub->whereJsonContains('subjects', (int)$subjectId);
            });
        }

        // Filter by topic if provided
        // Note: topics are accessed through quiz attempts, so find users who attempted quizzes with this topic
        if ($topicId) {
            $query->whereHas('quizAttempts', function ($sub) use ($topicId) {
                $sub->whereHas('quiz', function ($quizSub) use ($topicId) {
                    $quizSub->where('topic_id', $topicId);
                });
            });
        }

        // Filter by quiz if provided (users who have attempted the quiz)
        if ($quizId) {
            $query->whereHas('quizAttempts', function ($sub) use ($quizId) {
                $sub->where('quiz_id', $quizId);
            });
        }

        // Only include quizee users on the public leaderboard
        $query->where('role', 'quizee');

        // Validate sort_by allowed values
        $allowedSort = ['points', 'name', 'created_at'];
        if (!in_array($sortBy, $allowedSort)) {
            $sortBy = 'points';
        }

        // If sorting by points ensure nulls are treated as 0 (safe order)
        if ($sortBy === 'points') {
            // Order by points (nullable) and then name for deterministic ordering
            $query->orderByRaw("COALESCE(points, 0) {$sortDir}")
                  ->orderBy('name', 'asc');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        try {
            // Use the query's selected columns (avoid overriding with a columns list that
            // may not exist across different schema versions). This is more resilient
            // and avoids errors when migrations differ between environments.
            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            // Normalize collection items to a stable shape expected by frontend
            $paginated->getCollection()->transform(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name ?? ($u->email ?? 'Unknown'),
                    'avatar' => $u->avatar,
                    'points' => (int)($u->points ?? 0), // Force integer
                    'country' => $u->country ?? null,
                ];
            });

            return response()->json($paginated);
        } catch (\Exception $e) {
            // Log full exception with stack so debugging is easier
            \Log::error('Leaderboard query failed: ' . $e->getMessage(), ['exception' => $e]);

            // Return empty result set rather than 500 to avoid breaking public pages,
            // but include a helpful message in logs. Frontend will show the empty
            // pagination shape and our UI now renders table headers while loading.
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
