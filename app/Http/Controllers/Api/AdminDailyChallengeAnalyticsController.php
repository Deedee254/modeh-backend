<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDailyChallengeAnalyticsController extends Controller
{
    private function requireAdmin()
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }
        return null;
    }

    public function analytics(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'level_id' => 'nullable|integer|exists:levels,id',
            'grade_id' => 'nullable|integer|exists:grades,id',
        ]);

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->toDateString()
            : now()->toDateString();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->toDateString()
            : Carbon::parse($to)->subDays(29)->toDateString();

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $levelId = $validated['level_id'] ?? null;
        $gradeId = $validated['grade_id'] ?? null;

        $base = DB::table('daily_challenge_submissions as s')
            ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
            ->whereBetween('c.date', [$from, $to]);

        if ($levelId) $base->where('c.level_id', $levelId);
        if ($gradeId) $base->where('c.grade_id', $gradeId);

        $kpiRow = (clone $base)
            ->selectRaw('COUNT(*) as total_submissions')
            ->selectRaw('COUNT(DISTINCT s.user_id) as unique_users')
            ->selectRaw('AVG(s.score) as avg_score')
            ->selectRaw('MIN(s.score) as min_score')
            ->selectRaw('MAX(s.score) as max_score')
            ->selectRaw('AVG(CASE WHEN s.time_taken IS NULL THEN NULL ELSE s.time_taken END) as avg_time_taken')
            ->first();

        $returningUsers = (clone $base)
            ->select('s.user_id')
            ->groupBy('s.user_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        $eligibleUsersQuery = DB::table('quizees as q');
        if ($levelId) $eligibleUsersQuery->where('q.level_id', $levelId);
        if ($gradeId) $eligibleUsersQuery->where('q.grade_id', $gradeId);
        $eligibleUsers = (int) ($eligibleUsersQuery->count() ?? 0);

        $uniqueUsers = (int) ($kpiRow->unique_users ?? 0);
        $participationRate = $eligibleUsers > 0 ? ($uniqueUsers / $eligibleUsers) * 100 : 0;

        $trendRows = (clone $base)
            ->selectRaw('c.date as date')
            ->selectRaw('COUNT(*) as completions')
            ->selectRaw('COUNT(DISTINCT s.user_id) as users')
            ->selectRaw('AVG(s.score) as avg_score')
            ->selectRaw('AVG(CASE WHEN s.time_taken IS NULL THEN NULL ELSE s.time_taken END) as avg_time_taken')
            ->groupBy('c.date')
            ->orderBy('c.date', 'asc')
            ->get();

        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        $trendByDate = [];
        foreach ($trendRows as $r) {
            $trendByDate[$r->date] = $r;
        }

        $completionsSeries = [];
        $usersSeries = [];
        $avgScoreSeries = [];
        $avgTimeSeries = [];
        foreach ($dates as $date) {
            $r = $trendByDate[$date] ?? null;
            $completionsSeries[] = ['date' => $date, 'value' => (int) ($r->completions ?? 0)];
            $usersSeries[] = ['date' => $date, 'value' => (int) ($r->users ?? 0)];
            $avgScoreSeries[] = ['date' => $date, 'value' => (float) ($r->avg_score ?? 0)];
            $avgTimeSeries[] = ['date' => $date, 'value' => (float) ($r->avg_time_taken ?? 0)];
        }

        $uaBase = DB::table('user_achievements as ua')
            ->join('achievements as a', 'a.id', '=', 'ua.achievement_id')
            ->whereBetween('ua.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereIn('a.type', ['streak', 'daily_challenge']);

        if ($levelId || $gradeId) {
            $uaBase->join('quizees as q', 'q.user_id', '=', 'ua.user_id');
            if ($levelId) $uaBase->where('q.level_id', $levelId);
            if ($gradeId) $uaBase->where('q.grade_id', $gradeId);
        }

        $pointsRows = (clone $uaBase)
            ->selectRaw('a.type as type')
            ->selectRaw('COUNT(*) as unlocks')
            ->selectRaw('SUM(a.points) as points')
            ->groupBy('a.type')
            ->get();

        $pointsByType = [
            'streak' => ['unlocks' => 0, 'points' => 0],
            'daily_challenge' => ['unlocks' => 0, 'points' => 0],
        ];
        foreach ($pointsRows as $r) {
            $t = $r->type;
            if (!isset($pointsByType[$t])) continue;
            $pointsByType[$t] = [
                'unlocks' => (int) ($r->unlocks ?? 0),
                'points' => (int) ($r->points ?? 0),
            ];
        }

        $pointsTrend = (clone $uaBase)
            ->selectRaw('DATE(ua.created_at) as date')
            ->selectRaw("SUM(CASE WHEN a.type = 'streak' THEN a.points ELSE 0 END) as streak_points")
            ->selectRaw("SUM(CASE WHEN a.type = 'daily_challenge' THEN a.points ELSE 0 END) as daily_challenge_points")
            ->groupBy(DB::raw('DATE(ua.created_at)'))
            ->orderBy('date', 'asc')
            ->get();

        $pointsTrendByDate = [];
        foreach ($pointsTrend as $r) {
            $pointsTrendByDate[$r->date] = $r;
        }
        $streakPointsSeries = [];
        $dailyChallengePointsSeries = [];
        foreach ($dates as $date) {
            $r = $pointsTrendByDate[$date] ?? null;
            $streakPointsSeries[] = ['date' => $date, 'value' => (int) ($r->streak_points ?? 0)];
            $dailyChallengePointsSeries[] = ['date' => $date, 'value' => (int) ($r->daily_challenge_points ?? 0)];
        }

        $topBase = (clone $base)
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->selectRaw('s.user_id as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('COUNT(*) as completions')
            ->selectRaw('AVG(s.score) as avg_score')
            ->selectRaw('SUM(s.score) as total_score')
            ->selectRaw('AVG(CASE WHEN s.time_taken IS NULL THEN NULL ELSE s.time_taken END) as avg_time_taken')
            ->selectRaw('MIN(c.date) as first_date')
            ->selectRaw('MAX(c.date) as last_date')
            ->groupBy('s.user_id');

        $topByCompletions = (clone $topBase)
            ->orderByDesc('completions')
            ->orderByDesc('avg_score')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'completions' => (int) $r->completions,
                    'avg_score' => round((float) ($r->avg_score ?? 0), 2),
                    'avg_time_taken' => (int) round((float) ($r->avg_time_taken ?? 0)),
                    'last_date' => $r->last_date,
                ];
            })
            ->values();

        $topByAvgScore = (clone $topBase)
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('avg_score')
            ->orderByDesc('completions')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'completions' => (int) $r->completions,
                    'avg_score' => round((float) ($r->avg_score ?? 0), 2),
                    'avg_time_taken' => (int) round((float) ($r->avg_time_taken ?? 0)),
                    'last_date' => $r->last_date,
                ];
            })
            ->values();

        $topByTotalScore = (clone $topBase)
            ->orderByDesc('total_score')
            ->orderByDesc('completions')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'completions' => (int) $r->completions,
                    'total_score' => (int) ($r->total_score ?? 0),
                    'avg_score' => round((float) ($r->avg_score ?? 0), 2),
                    'last_date' => $r->last_date,
                ];
            })
            ->values();

        $streakEnd = Carbon::parse($to)->toDateString();
        $streakWindowFrom = Carbon::parse($streakEnd)->subDays(59)->toDateString();
        $streakRows = DB::table('daily_challenge_submissions as s')
            ->join('daily_challenges_cache as c', 'c.id', '=', 's.daily_challenge_cache_id')
            ->whereBetween('c.date', [$streakWindowFrom, $streakEnd])
            ->selectRaw('s.user_id as user_id, c.date as date')
            ->distinct()
            ->orderBy('c.date', 'desc');

        if ($levelId) $streakRows->where('c.level_id', $levelId);
        if ($gradeId) $streakRows->where('c.grade_id', $gradeId);

        $streakDatesByUser = [];
        foreach ($streakRows->get() as $r) {
            $uid = (int) $r->user_id;
            if (!isset($streakDatesByUser[$uid])) $streakDatesByUser[$uid] = [];
            $streakDatesByUser[$uid][$r->date] = true;
        }

        $streaks = [];
        $endDt = Carbon::parse($streakEnd);
        foreach ($streakDatesByUser as $uid => $dateSet) {
            $streak = 0;
            for ($i = 0; $i < 60; $i++) {
                $d = $endDt->copy()->subDays($i)->toDateString();
                if (isset($dateSet[$d])) $streak++;
                else break;
            }
            $streaks[$uid] = $streak;
        }

        arsort($streaks);
        $topStreakUserIds = array_slice(array_keys($streaks), 0, 10);
        $usersById = DB::table('users')
            ->whereIn('id', $topStreakUserIds)
            ->selectRaw('id, name, email, COALESCE(avatar_url, social_avatar) as avatar')
            ->get()
            ->keyBy('id');

        $streakLeaders = [];
        foreach ($topStreakUserIds as $uid) {
            $u = $usersById->get($uid);
            $streakLeaders[] = [
                'user_id' => (int) $uid,
                'name' => $u?->name,
                'email' => $u?->email,
                'avatar' => $u?->avatar,
                'streak_days' => (int) ($streaks[$uid] ?? 0),
            ];
        }

        $streakDistribution = [
            '0' => 0,
            '1_2' => 0,
            '3_4' => 0,
            '5_6' => 0,
            '7_plus' => 0,
        ];
        foreach ($streaks as $v) {
            if ($v <= 0) $streakDistribution['0']++;
            else if ($v <= 2) $streakDistribution['1_2']++;
            else if ($v <= 4) $streakDistribution['3_4']++;
            else if ($v <= 6) $streakDistribution['5_6']++;
            else $streakDistribution['7_plus']++;
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => [
                    'from' => $from,
                    'to' => $to,
                    'level_id' => $levelId,
                    'grade_id' => $gradeId,
                ],
                'kpis' => [
                    'total_completions' => (int) ($kpiRow->total_submissions ?? 0),
                    'unique_users' => $uniqueUsers,
                    'returning_users' => (int) $returningUsers,
                    'eligible_users' => $eligibleUsers,
                    'participation_rate' => round((float) $participationRate, 2),
                    'avg_score' => round((float) ($kpiRow->avg_score ?? 0), 2),
                    'min_score' => (int) ($kpiRow->min_score ?? 0),
                    'max_score' => (int) ($kpiRow->max_score ?? 0),
                    'avg_time_taken' => (int) round((float) ($kpiRow->avg_time_taken ?? 0)),
                    'points' => [
                        'streak' => $pointsByType['streak'],
                        'daily_challenge' => $pointsByType['daily_challenge'],
                    ],
                ],
                'series' => [
                    'completions' => $completionsSeries,
                    'users' => $usersSeries,
                    'avg_score' => $avgScoreSeries,
                    'avg_time_taken' => $avgTimeSeries,
                    'streak_points' => $streakPointsSeries,
                    'daily_challenge_points' => $dailyChallengePointsSeries,
                ],
                'top' => [
                    'by_completions' => $topByCompletions,
                    'by_avg_score' => $topByAvgScore,
                    'by_total_score' => $topByTotalScore,
                    'streak_leaders' => $streakLeaders,
                ],
                'streaks' => [
                    'window_days' => 60,
                    'as_of' => $streakEnd,
                    'distribution' => $streakDistribution,
                ],
            ],
        ]);
    }
}

