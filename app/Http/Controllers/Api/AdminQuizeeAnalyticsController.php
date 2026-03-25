<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminQuizeeAnalyticsController extends Controller
{
    private function requireAdmin()
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }
        return null;
    }

    private function resolveRange(array $validated): array
    {
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->toDateString()
            : now()->toDateString();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->toDateString()
            : Carbon::parse($to)->subDays(29)->toDateString();

        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    public function analytics(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        [$from, $to] = $this->resolveRange($validated);
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $totalQuizees = (int) DB::table('users')->where('role', 'quizee')->count();
        $newQuizees = (int) DB::table('users')
            ->where('role', 'quizee')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();

        $attemptsQ = DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('u.role', 'quizee')
            ->whereBetween('a.created_at', [$fromTs, $toTs]);

        $dailyQ = DB::table('daily_challenge_submissions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
            ->where('u.role', 'quizee')
            ->whereBetween('c.date', [$from, $to]);

        $totalAttempts = (int) (clone $attemptsQ)->count();
        $avgScore = (float) ((clone $attemptsQ)->avg('a.score') ?? 0);
        $pointsEarned = (float) ((clone $attemptsQ)->sum(DB::raw('COALESCE(a.points_earned,0)')) ?? 0);
        $dailyCompletions = (int) (clone $dailyQ)->count();

        // Active users: distinct users who did either quiz attempts or daily challenges in range.
        $activity = DB::query()->fromSub(
            DB::table('quiz_attempts as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('u.role', 'quizee')
                ->whereBetween('a.created_at', [$fromTs, $toTs])
                ->selectRaw('a.user_id as user_id, DATE(a.created_at) as date')
                ->unionAll(
                    DB::table('daily_challenge_submissions as s')
                        ->join('users as u2', 'u2.id', '=', 's.user_id')
                        ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
                        ->where('u2.role', 'quizee')
                        ->whereBetween('c.date', [$from, $to])
                        ->selectRaw('s.user_id as user_id, c.date as date')
                ),
            'act'
        );

        $activeUsers = (int) (clone $activity)->distinct('user_id')->count('user_id');

        $avgPoints = (float) (DB::table('users')->where('role', 'quizee')->avg('points') ?? 0);
        $totalBadgeUnlocks = (int) DB::table('user_badges as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('u.role', 'quizee')
            ->whereBetween('ub.created_at', [$fromTs, $toTs])
            ->count();

        // Build date array
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $signupRows = DB::table('users')
            ->where('role', 'quizee')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $signupByDate = [];
        foreach ($signupRows as $r) $signupByDate[$r->date] = (int) $r->value;

        $attemptRows = (clone $attemptsQ)
            ->selectRaw('DATE(a.created_at) as date')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $attemptByDate = [];
        foreach ($attemptRows as $r) $attemptByDate[$r->date] = $r;

        $dailyRows = (clone $dailyQ)
            ->selectRaw('c.date as date')
            ->selectRaw('COUNT(*) as completions')
            ->groupBy('c.date')
            ->orderBy('date', 'asc')
            ->get();
        $dailyByDate = [];
        foreach ($dailyRows as $r) $dailyByDate[$r->date] = (int) ($r->completions ?? 0);

        $activeRows = (clone $activity)
            ->selectRaw('date as date')
            ->selectRaw('COUNT(DISTINCT user_id) as active')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();
        $activeByDate = [];
        foreach ($activeRows as $r) $activeByDate[$r->date] = (int) ($r->active ?? 0);

        $series = [
            'signups' => [],
            'active_users' => [],
            'quiz_attempts' => [],
            'avg_score' => [],
            'points_earned' => [],
            'daily_challenge_completions' => [],
        ];

        foreach ($dates as $date) {
            $ar = $attemptByDate[$date] ?? null;
            $series['signups'][] = ['date' => $date, 'value' => (int) ($signupByDate[$date] ?? 0)];
            $series['active_users'][] = ['date' => $date, 'value' => (int) ($activeByDate[$date] ?? 0)];
            $series['quiz_attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['points_earned'][] = ['date' => $date, 'value' => (float) ($ar?->points_earned ?? 0)];
            $series['daily_challenge_completions'][] = ['date' => $date, 'value' => (int) ($dailyByDate[$date] ?? 0)];
        }

        $topByPoints = DB::table('users as u')
            ->where('u.role', 'quizee')
            ->leftJoin('quizees as q', 'q.user_id', '=', 'u.id')
            ->selectRaw('u.id, u.name, u.email, COALESCE(u.avatar_url, u.social_avatar) as avatar')
            ->selectRaw('u.points as points')
            ->selectRaw('COALESCE(q.current_streak,0) as current_streak')
            ->orderByDesc('u.points')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'points' => (float) ($r->points ?? 0),
                    'current_streak' => (int) ($r->current_streak ?? 0),
                ];
            })->values();

        $topByBadges = DB::table('user_badges as ub')
            ->join('users as u', 'u.id', '=', 'ub.user_id')
            ->where('u.role', 'quizee')
            ->selectRaw('ub.user_id as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('COUNT(*) as badges')
            ->groupBy('ub.user_id')
            ->orderByDesc('badges')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'badges' => (int) ($r->badges ?? 0),
                ];
            })->values();

        $topByPerformance = DB::table('quiz_attempts as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('u.role', 'quizee')
            ->whereBetween('a.created_at', [$fromTs, $toTs])
            ->whereNotNull('a.score')
            ->selectRaw('a.user_id as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->groupBy('a.user_id')
            ->havingRaw('COUNT(*) >= 5')
            ->orderByDesc('avg_score')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'attempts' => (int) ($r->attempts ?? 0),
                    'avg_score' => round((float) ($r->avg_score ?? 0), 2),
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'kpis' => [
                    'total_quizees' => $totalQuizees,
                    'new_quizees' => $newQuizees,
                    'active_quizees' => $activeUsers,
                    'total_attempts' => $totalAttempts,
                    'avg_score' => round($avgScore, 2),
                    'points_earned' => round($pointsEarned, 2),
                    'avg_points' => round($avgPoints, 2),
                    'daily_challenge_completions' => $dailyCompletions,
                    'badge_unlocks' => $totalBadgeUnlocks,
                ],
                'series' => $series,
                'top' => [
                    'by_points' => $topByPoints,
                    'by_badges' => $topByBadges,
                    'by_performance' => $topByPerformance,
                ],
            ],
        ]);
    }

    public function insights(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:200',
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        [$from, $to] = $this->resolveRange($validated);
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $page = (int) ($validated['page'] ?? 1);
        $limit = (int) ($validated['limit'] ?? 50);
        $search = trim((string) ($validated['search'] ?? ''));

        $badgeCounts = DB::table('user_badges')
            ->selectRaw('user_id, COUNT(*) as badges_count')
            ->groupBy('user_id');

        $attemptStats = DB::table('quiz_attempts')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('user_id, COUNT(*) as attempts_in_range, AVG(score) as avg_score_in_range, SUM(COALESCE(points_earned,0)) as points_earned_in_range')
            ->groupBy('user_id');

        $dailyStats = DB::table('daily_challenge_submissions as s')
            ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
            ->whereBetween('c.date', [$from, $to])
            ->selectRaw('s.user_id as user_id, COUNT(*) as daily_challenge_completions_in_range')
            ->groupBy('s.user_id');

        $lastAttempt = DB::table('quiz_attempts')
            ->selectRaw('user_id, MAX(created_at) as last_quiz_attempt_at')
            ->groupBy('user_id');

        $lastDaily = DB::table('daily_challenge_submissions')
            ->selectRaw('user_id, MAX(completed_at) as last_daily_challenge_at')
            ->groupBy('user_id');

        $query = DB::table('users as u')
            ->where('u.role', 'quizee')
            ->leftJoin('quizees as q', 'q.user_id', '=', 'u.id')
            ->leftJoin('grades as g', 'g.id', '=', 'q.grade_id')
            ->leftJoin('levels as l', 'l.id', '=', 'q.level_id')
            ->leftJoinSub($badgeCounts, 'bc', function ($join) {
                $join->on('bc.user_id', '=', 'u.id');
            })
            ->leftJoinSub($attemptStats, 'as', function ($join) {
                $join->on('as.user_id', '=', 'u.id');
            })
            ->leftJoinSub($dailyStats, 'ds', function ($join) {
                $join->on('ds.user_id', '=', 'u.id');
            })
            ->leftJoinSub($lastAttempt, 'la', function ($join) {
                $join->on('la.user_id', '=', 'u.id');
            })
            ->leftJoinSub($lastDaily, 'ld', function ($join) {
                $join->on('ld.user_id', '=', 'u.id');
            })
            ->selectRaw('u.id, u.name, u.email, COALESCE(u.avatar_url, u.social_avatar) as avatar, u.created_at')
            ->selectRaw('u.points as points')
            ->selectRaw('q.points as profile_points')
            ->selectRaw('COALESCE(q.current_streak,0) as current_streak')
            ->selectRaw('COALESCE(q.longest_streak,0) as longest_streak')
            ->selectRaw('g.name as grade_name, l.name as level_name')
            ->selectRaw('COALESCE(bc.badges_count,0) as badges_count')
            ->selectRaw('COALESCE(as.attempts_in_range,0) as attempts_in_range')
            ->selectRaw('COALESCE(as.avg_score_in_range,0) as avg_score_in_range')
            ->selectRaw('COALESCE(as.points_earned_in_range,0) as points_earned_in_range')
            ->selectRaw('COALESCE(ds.daily_challenge_completions_in_range,0) as daily_challenge_completions_in_range')
            ->selectRaw('la.last_quiz_attempt_at as last_quiz_attempt_at')
            ->selectRaw('ld.last_daily_challenge_at as last_daily_challenge_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', '%' . $search . '%')
                    ->orWhere('u.email', 'like', '%' . $search . '%');
            });
        }

        $total = (int) (clone $query)->count();
        $rows = $query->orderByDesc('u.created_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'created_at' => $r->created_at,
                    'level' => $r->level_name,
                    'grade' => $r->grade_name,
                    'points' => (float) ($r->points ?? 0),
                    'profile_points' => (int) ($r->profile_points ?? 0),
                    'current_streak' => (int) ($r->current_streak ?? 0),
                    'longest_streak' => (int) ($r->longest_streak ?? 0),
                    'badges_count' => (int) ($r->badges_count ?? 0),
                    'attempts' => [
                        'count' => (int) ($r->attempts_in_range ?? 0),
                        'avg_score' => round((float) ($r->avg_score_in_range ?? 0), 2),
                        'points_earned' => round((float) ($r->points_earned_in_range ?? 0), 2),
                    ],
                    'daily_challenges' => [
                        'completions' => (int) ($r->daily_challenge_completions_in_range ?? 0),
                    ],
                    'last_activity' => [
                        'quiz_attempt_at' => $r->last_quiz_attempt_at,
                        'daily_challenge_at' => $r->last_daily_challenge_at,
                    ],
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'users' => $rows,
            'meta' => [
                'from' => $from,
                'to' => $to,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'last_page' => $limit > 0 ? (int) ceil($total / $limit) : 1,
            ],
        ]);
    }

    public function userInsights(Request $request, $userId)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        [$from, $to] = $this->resolveRange($validated);
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        // Allow $userId to be numeric ID or an identifying string (email or name)
        $user = DB::table('users as u')
            ->where('u.role', 'quizee');

        if (ctype_digit(strval($userId))) {
            $user = $user->where('u.id', (int) $userId);
        } else {
                        $user = $user->where(function ($q) use ($userId) {
                                $q->where('u.email', $userId)
                                    ->orWhere('u.name', $userId);
                        });
        }

        $user = $user
            ->leftJoin('quizees as q', 'q.user_id', '=', 'u.id')
            ->leftJoin('grades as g', 'g.id', '=', 'q.grade_id')
            ->leftJoin('levels as l', 'l.id', '=', 'q.level_id')
            ->selectRaw('u.id, u.name, u.email, COALESCE(u.avatar_url, u.social_avatar) as avatar, u.points as points, u.created_at')
            ->selectRaw('q.points as profile_points, COALESCE(q.current_streak,0) as current_streak, COALESCE(q.longest_streak,0) as longest_streak')
            ->selectRaw('g.name as grade_name, l.name as level_name')
            ->first();

        if (!$user) return response()->json(['ok' => false, 'message' => 'Not found'], 404);

        $attemptsQ = DB::table('quiz_attempts as a')
            ->where('a.user_id', (int) $userId)
            ->whereBetween('a.created_at', [$fromTs, $toTs]);

        $attemptKpis = (clone $attemptsQ)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('MAX(a.score) as best_score')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->first();

        $dailyQ = DB::table('daily_challenge_submissions as s')
            ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
            ->where('s.user_id', (int) $userId)
            ->whereBetween('c.date', [$from, $to]);

        $dailyCount = (int) (clone $dailyQ)->count();

        $badgeCount = (int) DB::table('user_badges')->where('user_id', (int) $userId)->count();
        $badges = DB::table('user_badges as ub')
            ->join('badges as b', 'b.id', '=', 'ub.badge_id')
            ->where('ub.user_id', (int) $userId)
            ->orderBy('ub.earned_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => (int) $r->badge_id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'icon' => $r->icon,
                    'points_reward' => (int) ($r->points_reward ?? 0),
                    'earned_at' => $r->earned_at,
                ];
            })->values();

        // Date series
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $attemptRows = (clone $attemptsQ)
            ->selectRaw('DATE(a.created_at) as date')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $attemptByDate = [];
        foreach ($attemptRows as $r) $attemptByDate[$r->date] = $r;

        $dailyRows = (clone $dailyQ)
            ->selectRaw('c.date as date')
            ->selectRaw('COUNT(*) as completions')
            ->groupBy('c.date')
            ->orderBy('date', 'asc')
            ->get();
        $dailyByDate = [];
        foreach ($dailyRows as $r) $dailyByDate[$r->date] = (int) ($r->completions ?? 0);

        $series = [
            'quiz_attempts' => [],
            'avg_score' => [],
            'points_earned' => [],
            'daily_challenge_completions' => [],
        ];
        foreach ($dates as $date) {
            $ar = $attemptByDate[$date] ?? null;
            $series['quiz_attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['points_earned'][] = ['date' => $date, 'value' => (float) ($ar?->points_earned ?? 0)];
            $series['daily_challenge_completions'][] = ['date' => $date, 'value' => (int) ($dailyByDate[$date] ?? 0)];
        }

        $recentAttempts = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->leftJoin('subjects as s', 's.id', '=', 'q.subject_id')
            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
            ->where('a.user_id', (int) $userId)
            ->orderByDesc('a.created_at')
            ->limit(10)
            ->get([
                'a.id',
                'a.quiz_id',
                'a.score',
                'a.points_earned',
                'a.created_at',
                'q.title as quiz_title',
                's.name as subject_name',
                't.name as topic_name',
            ])
            ->map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'quiz_id' => (int) $r->quiz_id,
                    'quiz_title' => $r->quiz_title,
                    'subject' => $r->subject_name,
                    'topic' => $r->topic_name,
                    'score' => (float) ($r->score ?? 0),
                    'points_earned' => (float) ($r->points_earned ?? 0),
                    'created_at' => $r->created_at,
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'created_at' => $user->created_at,
                    'level' => $user->level_name,
                    'grade' => $user->grade_name,
                    'points' => (float) ($user->points ?? 0),
                    'profile_points' => (int) ($user->profile_points ?? 0),
                    'current_streak' => (int) ($user->current_streak ?? 0),
                    'longest_streak' => (int) ($user->longest_streak ?? 0),
                ],
                'kpis' => [
                    'attempts' => (int) ($attemptKpis->attempts ?? 0),
                    'avg_score' => round((float) ($attemptKpis->avg_score ?? 0), 2),
                    'best_score' => round((float) ($attemptKpis->best_score ?? 0), 2),
                    'points_earned' => round((float) ($attemptKpis->points_earned ?? 0), 2),
                    'daily_challenge_completions' => $dailyCount,
                    'badges' => $badgeCount,
                ],
                'series' => $series,
                'recent' => [
                    'attempts' => $recentAttempts,
                    'badges' => $badges,
                ],
            ],
        ]);
    }
}
