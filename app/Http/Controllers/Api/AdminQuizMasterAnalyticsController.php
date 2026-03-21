<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AdminQuizMasterAnalyticsController extends Controller
{
    private function resolveQuizMasterUserId(string $identifier): ?int
    {
        $identifier = trim($identifier);
        if ($identifier === '') return null;

        if (ctype_digit($identifier)) {
            $userId = (int) $identifier;
            $exists = DB::table('users')
                ->where('id', $userId)
                ->where('role', 'quiz-master')
                ->exists();

            return $exists ? $userId : null;
        }

        $normalized = strtolower($identifier);
        $slugLike = str_replace(' ', '-', $normalized);

        $match = DB::table('users')
            ->where('role', 'quiz-master')
            ->where(function ($q) use ($identifier, $normalized, $slugLike) {
                $q->whereRaw('LOWER(email) = ?', [$normalized])
                    ->orWhereRaw('LOWER(name) = ?', [$normalized])
                    ->orWhereRaw("LOWER(REPLACE(name, ' ', '-')) = ?", [$slugLike]);
            })
            ->select('id')
            ->first();

        if ($match) return (int) $match->id;

        $fallback = DB::table('users')
            ->where('role', 'quiz-master')
            ->whereRaw("LOWER(REPLACE(name, ' ', '-')) LIKE ?", ['%' . $slugLike . '%'])
            ->orderBy('id')
            ->select('id')
            ->first();

        return $fallback ? (int) $fallback->id : null;
    }

    private function requireAdmin()
    {
        $user = Auth::user();
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

        $totalQuizMasters = (int) DB::table('users')->where('role', 'quiz-master')->count();
        $newQuizMasters = (int) DB::table('users')
            ->where('role', 'quiz-master')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();

        $quizQ = DB::table('quizzes as q')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', DB::raw('IFNULL(q.created_by, q.user_id)'));
            })
            ->where('u.role', 'quiz-master');

        $quizzesCreated = (int) (clone $quizQ)->whereBetween('q.created_at', [$fromTs, $toTs])->count();
        $quizzesApproved = (int) (clone $quizQ)->whereBetween('q.created_at', [$fromTs, $toTs])->where('q.is_approved', 1)->count();
        $quizzesDraft = (int) (clone $quizQ)->whereBetween('q.created_at', [$fromTs, $toTs])->where('q.is_draft', 1)->count();

        $attemptsQ = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', DB::raw('IFNULL(q.created_by, q.user_id)'));
            })
            ->where('u.role', 'quiz-master')
            ->whereBetween('a.created_at', [$fromTs, $toTs]);

        $attemptCount = (int) (clone $attemptsQ)->count();
        $avgAttemptScore = (float) ((clone $attemptsQ)->avg('a.score') ?? 0);

        // Earnings from transactions (quiz-master_share) in range
        $earningsQ = DB::table('transactions as t')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', DB::raw('t.`quiz-master_id`'));
            })
            ->where('u.role', 'quiz-master')
            ->where('t.status', 'completed')
            ->whereBetween('t.created_at', [$fromTs, $toTs]);

        $earnings = (float) ((clone $earningsQ)->sum(DB::raw('COALESCE(t.`quiz-master_share`,0)')) ?? 0);
        $txCount = (int) ((clone $earningsQ)->count() ?? 0);

        // Date series
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $signupRows = DB::table('users')
            ->where('role', 'quiz-master')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $signupByDate = [];
        foreach ($signupRows as $r) $signupByDate[$r->date] = (int) $r->value;

        $quizRows = (clone $quizQ)
            ->whereBetween('q.created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(q.created_at) as date')
            ->selectRaw('COUNT(*) as quizzes')
            ->groupBy(DB::raw('DATE(q.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $quizByDate = [];
        foreach ($quizRows as $r) $quizByDate[$r->date] = (int) ($r->quizzes ?? 0);

        $attemptRows = (clone $attemptsQ)
            ->selectRaw('DATE(a.created_at) as date')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $attemptByDate = [];
        foreach ($attemptRows as $r) $attemptByDate[$r->date] = $r;

        $earningRows = (clone $earningsQ)
            ->selectRaw('DATE(t.created_at) as date')
            ->selectRaw('SUM(COALESCE(t.`quiz-master_share`,0)) as earnings')
            ->groupBy(DB::raw('DATE(t.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $earnByDate = [];
        foreach ($earningRows as $r) $earnByDate[$r->date] = (float) ($r->earnings ?? 0);

        $series = [
            'signups' => [],
            'quizzes_created' => [],
            'quiz_attempts' => [],
            'avg_attempt_score' => [],
            'earnings' => [],
        ];

        foreach ($dates as $date) {
            $ar = $attemptByDate[$date] ?? null;
            $series['signups'][] = ['date' => $date, 'value' => (int) ($signupByDate[$date] ?? 0)];
            $series['quizzes_created'][] = ['date' => $date, 'value' => (int) ($quizByDate[$date] ?? 0)];
            $series['quiz_attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_attempt_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['earnings'][] = ['date' => $date, 'value' => (float) ($earnByDate[$date] ?? 0)];
        }

        $topByEarnings = DB::table('transactions as t')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', DB::raw('t.`quiz-master_id`'));
            })
            ->where('u.role', 'quiz-master')
            ->where('t.status', 'completed')
            ->selectRaw('t.`quiz-master_id` as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('SUM(COALESCE(t.`quiz-master_share`,0)) as earnings')
            ->selectRaw('COUNT(*) as tx')
            ->groupBy(DB::raw('t.`quiz-master_id`'))
            ->orderByDesc('earnings')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'earnings' => (float) ($r->earnings ?? 0),
                    'transactions' => (int) ($r->tx ?? 0),
                ];
            })->values();

        $topByQuizzes = DB::table('quizzes as q')
            ->join('users as u', function ($join) {
                $join->on('u.id', '=', DB::raw('IFNULL(q.created_by, q.user_id)'));
            })
            ->where('u.role', 'quiz-master')
            ->selectRaw('IFNULL(q.created_by, q.user_id) as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('COUNT(*) as quizzes')
            ->groupBy(DB::raw('IFNULL(q.created_by, q.user_id)'))
            ->orderByDesc('quizzes')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'quizzes' => (int) ($r->quizzes ?? 0),
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'kpis' => [
                    'total_quiz_masters' => $totalQuizMasters,
                    'new_quiz_masters' => $newQuizMasters,
                    'quizzes_created' => $quizzesCreated,
                    'quizzes_approved' => $quizzesApproved,
                    'quizzes_draft' => $quizzesDraft,
                    'attempts' => $attemptCount,
                    'avg_attempt_score' => round($avgAttemptScore, 2),
                    'earnings' => round($earnings, 2),
                    'transactions' => $txCount,
                ],
                'series' => $series,
                'top' => [
                    'by_earnings' => $topByEarnings,
                    'by_quizzes' => $topByQuizzes,
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

        $txAll = DB::table('transactions as t')
            ->selectRaw("t.`quiz-master_id` as user_id")
            ->selectRaw("SUM(CASE WHEN t.status = 'completed' THEN COALESCE(t.`quiz-master_share`,0) ELSE 0 END) as lifetime_earnings")
            ->selectRaw("SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as lifetime_transactions")
            ->groupBy(DB::raw("t.`quiz-master_id`"));

        $txRange = DB::table('transactions as t')
            ->whereBetween('t.created_at', [$fromTs, $toTs])
            ->selectRaw("t.`quiz-master_id` as user_id")
            ->selectRaw("SUM(CASE WHEN t.status = 'completed' THEN COALESCE(t.`quiz-master_share`,0) ELSE 0 END) as earnings_in_range")
            ->selectRaw("SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as transactions_in_range")
            ->groupBy(DB::raw("t.`quiz-master_id`"));

        $quizAll = DB::table('quizzes as q')
            ->selectRaw('IFNULL(q.created_by, q.user_id) as user_id')
            ->selectRaw('COUNT(*) as total_quizzes')
            ->selectRaw('SUM(CASE WHEN q.is_approved = 1 THEN 1 ELSE 0 END) as approved_quizzes')
            ->selectRaw('SUM(CASE WHEN q.is_draft = 1 THEN 1 ELSE 0 END) as draft_quizzes')
            ->selectRaw('SUM(CASE WHEN q.is_paid = 1 THEN 1 ELSE 0 END) as paid_quizzes')
            ->groupBy(DB::raw('IFNULL(q.created_by, q.user_id)'));

        $quizRange = DB::table('quizzes as q')
            ->whereBetween('q.created_at', [$fromTs, $toTs])
            ->selectRaw('IFNULL(q.created_by, q.user_id) as user_id')
            ->selectRaw('COUNT(*) as quizzes_in_range')
            ->groupBy(DB::raw('IFNULL(q.created_by, q.user_id)'));

        $attemptRange = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->whereBetween('a.created_at', [$fromTs, $toTs])
            ->selectRaw('IFNULL(q.created_by, q.user_id) as user_id')
            ->selectRaw('COUNT(*) as attempts_in_range')
            ->selectRaw('AVG(a.score) as avg_score_in_range')
            ->groupBy(DB::raw('IFNULL(q.created_by, q.user_id)'));

        $topicsAll = DB::table('topics')
            ->whereNotNull('created_by')
            ->selectRaw('created_by as user_id, COUNT(*) as topics_created')
            ->groupBy('created_by');

        $query = DB::table('users as u')
            ->where('u.role', 'quiz-master')
            ->leftJoin('wallets as w', 'w.user_id', '=', 'u.id')
            ->leftJoin('quiz_masters as qm', 'qm.user_id', '=', 'u.id')
            ->leftJoin('grades as g', 'g.id', '=', 'qm.grade_id')
            ->leftJoin('levels as l', 'l.id', '=', 'qm.level_id')
            ->leftJoinSub($txAll, 'txa', function ($join) {
                $join->on('txa.user_id', '=', 'u.id');
            })
            ->leftJoinSub($txRange, 'txr', function ($join) {
                $join->on('txr.user_id', '=', 'u.id');
            })
            ->leftJoinSub($quizAll, 'qa', function ($join) {
                $join->on('qa.user_id', '=', 'u.id');
            })
            ->leftJoinSub($quizRange, 'qr', function ($join) {
                $join->on('qr.user_id', '=', 'u.id');
            })
            ->leftJoinSub($attemptRange, 'ar', function ($join) {
                $join->on('ar.user_id', '=', 'u.id');
            })
            ->leftJoinSub($topicsAll, 'ta', function ($join) {
                $join->on('ta.user_id', '=', 'u.id');
            })
            ->selectRaw('u.id, u.slug, u.name, u.email, COALESCE(u.avatar_url, u.social_avatar) as avatar, u.created_at')
            ->selectRaw('g.name as grade_name, l.name as level_name')
            ->selectRaw('COALESCE(w.available,0) as wallet_available')
            ->selectRaw('COALESCE(w.withdrawn_pending,0) as wallet_withdrawn_pending')
            ->selectRaw('COALESCE(w.lifetime_earned,0) as wallet_lifetime_earned')
            ->selectRaw('COALESCE(txa.lifetime_earnings,0) as lifetime_earnings')
            ->selectRaw('COALESCE(txa.lifetime_transactions,0) as lifetime_transactions')
            ->selectRaw('COALESCE(txr.earnings_in_range,0) as earnings_in_range')
            ->selectRaw('COALESCE(txr.transactions_in_range,0) as transactions_in_range')
            ->selectRaw('COALESCE(qa.total_quizzes,0) as total_quizzes')
            ->selectRaw('COALESCE(qa.approved_quizzes,0) as approved_quizzes')
            ->selectRaw('COALESCE(qa.draft_quizzes,0) as draft_quizzes')
            ->selectRaw('COALESCE(qa.paid_quizzes,0) as paid_quizzes')
            ->selectRaw('COALESCE(qr.quizzes_in_range,0) as quizzes_in_range')
            ->selectRaw('COALESCE(ar.attempts_in_range,0) as attempts_in_range')
            ->selectRaw('COALESCE(ar.avg_score_in_range,0) as avg_score_in_range')
            ->selectRaw('COALESCE(ta.topics_created,0) as topics_created');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', '%' . $search . '%')
                    ->orWhere('u.email', 'like', '%' . $search . '%');
            });
        }

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderByDesc('u.created_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'slug' => $r->slug ?? null,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'avatar_url' => $r->avatar,
                    'created_at' => $r->created_at,
                    'grade' => $r->grade_name,
                    'level' => $r->level_name,
                    'wallet' => [
                        'available' => (float) ($r->wallet_available ?? 0),
                        'withdrawn_pending' => (float) ($r->wallet_withdrawn_pending ?? 0),
                        'lifetime_earned' => (float) ($r->wallet_lifetime_earned ?? 0),
                    ],
                    'earnings' => [
                        'lifetime' => (float) ($r->lifetime_earnings ?? 0),
                        'in_range' => (float) ($r->earnings_in_range ?? 0),
                        'transactions_lifetime' => (int) ($r->lifetime_transactions ?? 0),
                        'transactions_in_range' => (int) ($r->transactions_in_range ?? 0),
                    ],
                    'quizzes' => [
                        'total' => (int) ($r->total_quizzes ?? 0),
                        'approved' => (int) ($r->approved_quizzes ?? 0),
                        'draft' => (int) ($r->draft_quizzes ?? 0),
                        'paid' => (int) ($r->paid_quizzes ?? 0),
                        'in_range' => (int) ($r->quizzes_in_range ?? 0),
                    ],
                    'performance' => [
                        'attempts_in_range' => (int) ($r->attempts_in_range ?? 0),
                        'avg_score_in_range' => round((float) ($r->avg_score_in_range ?? 0), 2),
                    ],
                    'content' => [
                        'topics_created' => (int) ($r->topics_created ?? 0),
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

        $resolvedUserId = $this->resolveQuizMasterUserId((string) $userId);
        if (!$resolvedUserId) {
            return response()->json(['ok' => false, 'message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        [$from, $to] = $this->resolveRange($validated);
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $user = DB::table('users as u')
            ->where('u.id', $resolvedUserId)
            ->where('u.role', 'quiz-master')
            ->leftJoin('wallets as w', 'w.user_id', '=', 'u.id')
            ->leftJoin('quiz_masters as qm', 'qm.user_id', '=', 'u.id')
            ->leftJoin('grades as g', 'g.id', '=', 'qm.grade_id')
            ->leftJoin('levels as l', 'l.id', '=', 'qm.level_id')
            ->selectRaw('u.id, u.name, u.email, COALESCE(u.avatar_url, u.social_avatar) as avatar, u.created_at')
            ->selectRaw('COALESCE(w.available,0) as wallet_available, COALESCE(w.withdrawn_pending,0) as wallet_withdrawn_pending, COALESCE(w.lifetime_earned,0) as wallet_lifetime_earned')
            ->selectRaw('g.name as grade_name, l.name as level_name')
            ->first();

        if (!$user) return response()->json(['ok' => false, 'message' => 'Not found'], 404);

        $quizzesAll = DB::table('quizzes as q')
            ->whereRaw('IFNULL(q.created_by, q.user_id) = ?', [$resolvedUserId]);
        $quizzesRange = (clone $quizzesAll)->whereBetween('q.created_at', [$fromTs, $toTs]);

        $quizKpis = (clone $quizzesAll)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN q.is_approved = 1 THEN 1 ELSE 0 END) as approved')
            ->selectRaw('SUM(CASE WHEN q.is_draft = 1 THEN 1 ELSE 0 END) as draft')
            ->selectRaw('SUM(CASE WHEN q.is_paid = 1 THEN 1 ELSE 0 END) as paid')
            ->first();

        $attemptsQ = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->whereRaw('IFNULL(q.created_by, q.user_id) = ?', [$resolvedUserId]);

        $attemptsRange = (clone $attemptsQ)->whereBetween('a.created_at', [$fromTs, $toTs]);
        $attemptKpis = (clone $attemptsRange)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('MAX(a.score) as best_score')
            ->first();

        $txAll = DB::table('transactions as t')
            ->whereRaw("t.`quiz-master_id` = ?", [$resolvedUserId])
            ->where('t.status', 'completed');
        $txRange = (clone $txAll)->whereBetween('t.created_at', [$fromTs, $toTs]);
        $earningsAll = (float) ((clone $txAll)->sum(DB::raw("COALESCE(t.`quiz-master_share`,0)")) ?? 0);
        $earningsRange = (float) ((clone $txRange)->sum(DB::raw("COALESCE(t.`quiz-master_share`,0)")) ?? 0);

        // Date series
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $quizRows = (clone $quizzesRange)
            ->selectRaw('DATE(q.created_at) as date, COUNT(*) as quizzes')
            ->groupBy(DB::raw('DATE(q.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $quizByDate = [];
        foreach ($quizRows as $r) $quizByDate[$r->date] = (int) ($r->quizzes ?? 0);

        $attemptRows = (clone $attemptsRange)
            ->selectRaw('DATE(a.created_at) as date, COUNT(*) as attempts, AVG(a.score) as avg_score')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $attemptByDate = [];
        foreach ($attemptRows as $r) $attemptByDate[$r->date] = $r;

        $earnRows = (clone $txRange)
            ->selectRaw('DATE(t.created_at) as date, SUM(COALESCE(t.`quiz-master_share`,0)) as earnings')
            ->groupBy(DB::raw('DATE(t.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $earnByDate = [];
        foreach ($earnRows as $r) $earnByDate[$r->date] = (float) ($r->earnings ?? 0);

        $series = [
            'quizzes_created' => [],
            'quiz_attempts' => [],
            'avg_attempt_score' => [],
            'earnings' => [],
        ];
        foreach ($dates as $date) {
            $ar = $attemptByDate[$date] ?? null;
            $series['quizzes_created'][] = ['date' => $date, 'value' => (int) ($quizByDate[$date] ?? 0)];
            $series['quiz_attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_attempt_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['earnings'][] = ['date' => $date, 'value' => (float) ($earnByDate[$date] ?? 0)];
        }

        $taxonomy = DB::table('quizzes as q')
            ->leftJoin('subjects as s', 's.id', '=', 'q.subject_id')
            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
            ->leftJoin('grades as g', 'g.id', '=', 'q.grade_id')
            ->leftJoin('levels as l', 'l.id', '=', 'q.level_id')
            ->whereRaw('IFNULL(q.created_by, q.user_id) = ?', [$resolvedUserId]);

        $topSubjects = (clone $taxonomy)
            ->selectRaw('q.subject_id as id, COALESCE(s.name, \"Unassigned\") as name, COUNT(*) as quizzes')
            ->groupBy('q.subject_id', 's.name')
            ->orderByDesc('quizzes')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'quizzes' => (int) $r->quizzes])
            ->values();

        $topTopics = (clone $taxonomy)
            ->selectRaw('q.topic_id as id, COALESCE(t.name, \"Unassigned\") as name, COUNT(*) as quizzes')
            ->groupBy('q.topic_id', 't.name')
            ->orderByDesc('quizzes')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'quizzes' => (int) $r->quizzes])
            ->values();

        $topGrades = (clone $taxonomy)
            ->selectRaw('q.grade_id as id, COALESCE(g.name, \"Unassigned\") as name, COUNT(*) as quizzes')
            ->groupBy('q.grade_id', 'g.name')
            ->orderByDesc('quizzes')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'quizzes' => (int) $r->quizzes])
            ->values();

        $topLevels = (clone $taxonomy)
            ->selectRaw('q.level_id as id, COALESCE(l.name, \"Unassigned\") as name, COUNT(*) as quizzes')
            ->groupBy('q.level_id', 'l.name')
            ->orderByDesc('quizzes')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'quizzes' => (int) $r->quizzes])
            ->values();

        $topQuizzes = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id')
            ->whereRaw('IFNULL(q.created_by, q.user_id) = ?', [$resolvedUserId])
            ->whereBetween('a.created_at', [$fromTs, $toTs])
            ->selectRaw('a.quiz_id as quiz_id')
            ->selectRaw('MAX(q.title) as title')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->groupBy('a.quiz_id')
            ->orderByDesc('attempts')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'quiz_id' => (int) $r->quiz_id,
                    'title' => $r->title,
                    'attempts' => (int) ($r->attempts ?? 0),
                    'avg_score' => round((float) ($r->avg_score ?? 0), 2),
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
                    'grade' => $user->grade_name,
                    'level' => $user->level_name,
                    'wallet' => [
                        'available' => (float) ($user->wallet_available ?? 0),
                        'withdrawn_pending' => (float) ($user->wallet_withdrawn_pending ?? 0),
                        'lifetime_earned' => (float) ($user->wallet_lifetime_earned ?? 0),
                    ],
                ],
                'kpis' => [
                    'quizzes_total' => (int) ($quizKpis->total ?? 0),
                    'quizzes_approved' => (int) ($quizKpis->approved ?? 0),
                    'quizzes_draft' => (int) ($quizKpis->draft ?? 0),
                    'quizzes_paid' => (int) ($quizKpis->paid ?? 0),
                    'attempts' => (int) ($attemptKpis->attempts ?? 0),
                    'avg_attempt_score' => round((float) ($attemptKpis->avg_score ?? 0), 2),
                    'best_attempt_score' => round((float) ($attemptKpis->best_score ?? 0), 2),
                    'earnings_lifetime' => round($earningsAll, 2),
                    'earnings_in_range' => round($earningsRange, 2),
                ],
                'series' => $series,
                'breakdown' => [
                    'subjects' => $topSubjects,
                    'topics' => $topTopics,
                    'grades' => $topGrades,
                    'levels' => $topLevels,
                ],
                'top_quizzes' => $topQuizzes,
            ],
        ]);
    }
}
