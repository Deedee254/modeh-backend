<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminQuizAnalyticsController extends Controller
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
            'subject_id' => 'nullable|integer|exists:subjects,id',
            'topic_id' => 'nullable|integer|exists:topics,id',
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : now()->toDateString();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $levelId = $validated['level_id'] ?? null;
        $gradeId = $validated['grade_id'] ?? null;
        $subjectId = $validated['subject_id'] ?? null;
        $topicId = $validated['topic_id'] ?? null;

        $quizBase = DB::table('quizzes as q');
        if ($levelId) $quizBase->where('q.level_id', $levelId);
        if ($gradeId) $quizBase->where('q.grade_id', $gradeId);
        if ($subjectId) $quizBase->where('q.subject_id', $subjectId);
        if ($topicId) $quizBase->where('q.topic_id', $topicId);

        $totalQuizzes = (int) (clone $quizBase)->count();

        $statusRow = (clone $quizBase)
            ->selectRaw('SUM(CASE WHEN q.is_approved = 1 THEN 1 ELSE 0 END) as approved')
            ->selectRaw('SUM(CASE WHEN q.is_draft = 1 THEN 1 ELSE 0 END) as drafts')
            ->selectRaw('SUM(CASE WHEN q.is_paid = 1 THEN 1 ELSE 0 END) as paid')
            ->selectRaw('SUM(CASE WHEN COALESCE(q.is_institutional,0) = 1 THEN 1 ELSE 0 END) as institutional')
            ->first();

        $quizzesCreatedInRange = (int) (clone $quizBase)->whereBetween('q.created_at', [$fromTs, $toTs])->count();

        // Questions tied to quizzes (exclude banked questions)
        $questionBase = DB::table('questions as qs')
            ->join('quizzes as q', 'q.id', '=', 'qs.quiz_id');
        if ($levelId) $questionBase->where('q.level_id', $levelId);
        if ($gradeId) $questionBase->where('q.grade_id', $gradeId);
        if ($subjectId) $questionBase->where('q.subject_id', $subjectId);
        if ($topicId) $questionBase->where('q.topic_id', $topicId);

        $totalQuestions = (int) (clone $questionBase)->count();
        $questionsCreatedInRange = (int) (clone $questionBase)->whereBetween('qs.created_at', [$fromTs, $toTs])->count();

        // Attempts
        $attemptBase = DB::table('quiz_attempts as a')
            ->join('quizzes as q', 'q.id', '=', 'a.quiz_id');
        if ($levelId) $attemptBase->where('q.level_id', $levelId);
        if ($gradeId) $attemptBase->where('q.grade_id', $gradeId);
        if ($subjectId) $attemptBase->where('q.subject_id', $subjectId);
        if ($topicId) $attemptBase->where('q.topic_id', $topicId);
        $attemptBase->whereBetween('a.created_at', [$fromTs, $toTs]);

        $attemptRow = (clone $attemptBase)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('COUNT(DISTINCT a.user_id) as unique_users')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('AVG(a.total_time_seconds) as avg_time')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->selectRaw('SUM(CASE WHEN COALESCE(a.paid_for,0) = 1 THEN 1 ELSE 0 END) as paid_attempts')
            ->selectRaw('SUM(CASE WHEN COALESCE(a.institution_access,0) = 1 THEN 1 ELSE 0 END) as institution_attempts')
            ->first();

        // Likes
        $likesBase = DB::table('quiz_likes as l')
            ->join('quizzes as q', 'q.id', '=', 'l.quiz_id');
        if ($levelId) $likesBase->where('q.level_id', $levelId);
        if ($gradeId) $likesBase->where('q.grade_id', $gradeId);
        if ($subjectId) $likesBase->where('q.subject_id', $subjectId);
        if ($topicId) $likesBase->where('q.topic_id', $topicId);
        $likesBase->whereBetween('l.created_at', [$fromTs, $toTs]);
        $likesInRange = (int) (clone $likesBase)->count();

        // Revenue (payments only; quiz_id must be present)
        $txBase = DB::table('transactions as t')
            ->whereNotNull('t.quiz_id')
            ->where('t.type', 'payment')
            ->whereIn('t.status', ['confirmed', 'completed'])
            ->whereBetween('t.created_at', [$fromTs, $toTs]);
        if ($levelId || $gradeId || $subjectId || $topicId) {
            $txBase->join('quizzes as q', 'q.id', '=', 't.quiz_id');
            if ($levelId) $txBase->where('q.level_id', $levelId);
            if ($gradeId) $txBase->where('q.grade_id', $gradeId);
            if ($subjectId) $txBase->where('q.subject_id', $subjectId);
            if ($topicId) $txBase->where('q.topic_id', $topicId);
        }

        $txRow = (clone $txBase)
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('SUM(COALESCE(t.amount,0)) as gross')
            ->selectRaw('SUM(COALESCE(t.platform_share,0)) as platform_share')
            ->selectRaw('SUM(COALESCE(t.`quiz-master_share`,0)) as quiz_master_share')
            ->selectRaw('SUM(COALESCE(t.affiliate_share,0)) as affiliate_share')
            ->first();

        // Date series
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $quizRows = (clone $quizBase)
            ->whereBetween('q.created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(q.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(q.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $quizByDate = [];
        foreach ($quizRows as $r) $quizByDate[$r->date] = (int) ($r->value ?? 0);

        $questionRows = (clone $questionBase)
            ->whereBetween('qs.created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(qs.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(qs.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $questionByDate = [];
        foreach ($questionRows as $r) $questionByDate[$r->date] = (int) ($r->value ?? 0);

        $attemptRows = (clone $attemptBase)
            ->selectRaw('DATE(a.created_at) as date')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $attemptByDate = [];
        foreach ($attemptRows as $r) $attemptByDate[$r->date] = $r;

        $likeRows = (clone $likesBase)
            ->selectRaw('DATE(l.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(l.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $likeByDate = [];
        foreach ($likeRows as $r) $likeByDate[$r->date] = (int) ($r->value ?? 0);

        $revRows = (clone $txBase)
            ->selectRaw('DATE(t.created_at) as date, SUM(COALESCE(t.amount,0)) as gross')
            ->groupBy(DB::raw('DATE(t.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $revByDate = [];
        foreach ($revRows as $r) $revByDate[$r->date] = (float) ($r->gross ?? 0);

        $series = [
            'quizzes_created' => [],
            'questions_created' => [],
            'attempts' => [],
            'avg_score' => [],
            'points_earned' => [],
            'likes' => [],
            'revenue' => [],
        ];
        foreach ($dates as $date) {
            $ar = $attemptByDate[$date] ?? null;
            $series['quizzes_created'][] = ['date' => $date, 'value' => (int) ($quizByDate[$date] ?? 0)];
            $series['questions_created'][] = ['date' => $date, 'value' => (int) ($questionByDate[$date] ?? 0)];
            $series['attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['points_earned'][] = ['date' => $date, 'value' => (float) ($ar?->points_earned ?? 0)];
            $series['likes'][] = ['date' => $date, 'value' => (int) ($likeByDate[$date] ?? 0)];
            $series['revenue'][] = ['date' => $date, 'value' => (float) ($revByDate[$date] ?? 0)];
        }

        // Top quizzes
	        $topByAttempts = (clone $attemptBase)
	            ->join('quizzes as q2', 'q2.id', '=', 'a.quiz_id')
	            ->selectRaw('a.quiz_id as quiz_id')
	            ->selectRaw('MAX(q2.title) as title')
	            ->selectRaw('MAX(q2.slug) as slug')
	            ->selectRaw('COUNT(*) as attempts')
	            ->selectRaw('AVG(a.score) as avg_score')
	            ->groupBy('a.quiz_id')
	            ->orderByDesc('attempts')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'quiz_id' => (int) $r->quiz_id,
	                'title' => $r->title,
	                'slug' => $r->slug,
	                'attempts' => (int) ($r->attempts ?? 0),
	                'avg_score' => round((float) ($r->avg_score ?? 0), 2),
	            ])->values();

	        $topByLikes = DB::table('quiz_likes as l')
	            ->join('quizzes as q', 'q.id', '=', 'l.quiz_id')
	            ->whereBetween('l.created_at', [$fromTs, $toTs])
	            ->when($levelId, fn($q) => $q->where('q.level_id', $levelId))
	            ->when($gradeId, fn($q) => $q->where('q.grade_id', $gradeId))
	            ->when($subjectId, fn($q) => $q->where('q.subject_id', $subjectId))
	            ->when($topicId, fn($q) => $q->where('q.topic_id', $topicId))
	            ->selectRaw('l.quiz_id as quiz_id')
	            ->selectRaw('MAX(q.title) as title')
	            ->selectRaw('MAX(q.slug) as slug')
	            ->selectRaw('COUNT(*) as likes')
	            ->groupBy('l.quiz_id')
	            ->orderByDesc('likes')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'quiz_id' => (int) $r->quiz_id,
	                'title' => $r->title,
	                'slug' => $r->slug,
	                'likes' => (int) ($r->likes ?? 0),
	            ])->values();

	        $topByRevenue = (clone $txBase)
	            ->leftJoin('quizzes as q2', 'q2.id', '=', 't.quiz_id')
	            ->selectRaw('t.quiz_id as quiz_id')
	            ->selectRaw('MAX(q2.title) as title')
	            ->selectRaw('MAX(q2.slug) as slug')
	            ->selectRaw('SUM(COALESCE(t.amount,0)) as gross')
	            ->groupBy('t.quiz_id')
	            ->orderByDesc('gross')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'quiz_id' => (int) $r->quiz_id,
	                'title' => $r->title,
	                'slug' => $r->slug,
	                'gross' => (float) ($r->gross ?? 0),
	            ])->values();

        // Breakdown by taxonomy (quizzes + attempts + avg score)
	        $breakdownTopic = DB::table('quizzes as q')
	            ->leftJoin('topics as t', 't.id', '=', 'q.topic_id')
	            ->leftJoin('quiz_attempts as a', function ($join) use ($fromTs, $toTs) {
	                $join->on('a.quiz_id', '=', 'q.id')->whereBetween('a.created_at', [$fromTs, $toTs]);
	            })
	            ->when($levelId, fn($q) => $q->where('q.level_id', $levelId))
	            ->when($gradeId, fn($q) => $q->where('q.grade_id', $gradeId))
	            ->when($subjectId, fn($q) => $q->where('q.subject_id', $subjectId))
	            ->when($topicId, fn($q) => $q->where('q.topic_id', $topicId))
	            ->selectRaw('q.topic_id as id, COALESCE(t.name, "Unassigned") as name')
	            ->selectRaw('COUNT(DISTINCT q.id) as quizzes')
	            ->selectRaw('COUNT(a.id) as attempts')
	            ->selectRaw('AVG(a.score) as avg_score')
	            ->groupBy('q.topic_id', 't.name')
	            ->orderByDesc('attempts')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'id' => $r->id,
	                'name' => $r->name,
	                'quizzes' => (int) ($r->quizzes ?? 0),
	                'attempts' => (int) ($r->attempts ?? 0),
	                'avg_score' => round((float) ($r->avg_score ?? 0), 2),
	            ])->values();

	        $breakdownGrade = DB::table('quizzes as q')
	            ->leftJoin('grades as g', 'g.id', '=', 'q.grade_id')
	            ->leftJoin('quiz_attempts as a', function ($join) use ($fromTs, $toTs) {
	                $join->on('a.quiz_id', '=', 'q.id')->whereBetween('a.created_at', [$fromTs, $toTs]);
	            })
	            ->when($levelId, fn($q) => $q->where('q.level_id', $levelId))
	            ->when($gradeId, fn($q) => $q->where('q.grade_id', $gradeId))
	            ->when($subjectId, fn($q) => $q->where('q.subject_id', $subjectId))
	            ->when($topicId, fn($q) => $q->where('q.topic_id', $topicId))
	            ->selectRaw('q.grade_id as id, COALESCE(g.name, "Unassigned") as name')
	            ->selectRaw('COUNT(DISTINCT q.id) as quizzes')
	            ->selectRaw('COUNT(a.id) as attempts')
	            ->selectRaw('AVG(a.score) as avg_score')
	            ->groupBy('q.grade_id', 'g.name')
	            ->orderByDesc('attempts')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'id' => $r->id,
	                'name' => $r->name,
	                'quizzes' => (int) ($r->quizzes ?? 0),
	                'attempts' => (int) ($r->attempts ?? 0),
	                'avg_score' => round((float) ($r->avg_score ?? 0), 2),
	            ])->values();

	        $breakdownLevel = DB::table('quizzes as q')
	            ->leftJoin('levels as l', 'l.id', '=', 'q.level_id')
	            ->leftJoin('quiz_attempts as a', function ($join) use ($fromTs, $toTs) {
	                $join->on('a.quiz_id', '=', 'q.id')->whereBetween('a.created_at', [$fromTs, $toTs]);
	            })
	            ->when($levelId, fn($q) => $q->where('q.level_id', $levelId))
	            ->when($gradeId, fn($q) => $q->where('q.grade_id', $gradeId))
	            ->when($subjectId, fn($q) => $q->where('q.subject_id', $subjectId))
	            ->when($topicId, fn($q) => $q->where('q.topic_id', $topicId))
	            ->selectRaw('q.level_id as id, COALESCE(l.name, "Unassigned") as name')
	            ->selectRaw('COUNT(DISTINCT q.id) as quizzes')
	            ->selectRaw('COUNT(a.id) as attempts')
	            ->selectRaw('AVG(a.score) as avg_score')
	            ->groupBy('q.level_id', 'l.name')
	            ->orderByDesc('attempts')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'id' => $r->id,
	                'name' => $r->name,
	                'quizzes' => (int) ($r->quizzes ?? 0),
	                'attempts' => (int) ($r->attempts ?? 0),
	                'avg_score' => round((float) ($r->avg_score ?? 0), 2),
	            ])->values();

	        $breakdownSubject = DB::table('quizzes as q')
	            ->leftJoin('subjects as s', 's.id', '=', 'q.subject_id')
	            ->leftJoin('quiz_attempts as a', function ($join) use ($fromTs, $toTs) {
	                $join->on('a.quiz_id', '=', 'q.id')->whereBetween('a.created_at', [$fromTs, $toTs]);
	            })
	            ->when($levelId, fn($q) => $q->where('q.level_id', $levelId))
	            ->when($gradeId, fn($q) => $q->where('q.grade_id', $gradeId))
	            ->when($subjectId, fn($q) => $q->where('q.subject_id', $subjectId))
	            ->when($topicId, fn($q) => $q->where('q.topic_id', $topicId))
	            ->selectRaw('q.subject_id as id, COALESCE(s.name, "Unassigned") as name')
	            ->selectRaw('COUNT(DISTINCT q.id) as quizzes')
	            ->selectRaw('COUNT(a.id) as attempts')
	            ->selectRaw('AVG(a.score) as avg_score')
	            ->groupBy('q.subject_id', 's.name')
	            ->orderByDesc('attempts')
	            ->limit(10)
	            ->get()
	            ->map(fn($r) => [
	                'id' => $r->id,
	                'name' => $r->name,
	                'quizzes' => (int) ($r->quizzes ?? 0),
	                'attempts' => (int) ($r->attempts ?? 0),
	                'avg_score' => round((float) ($r->avg_score ?? 0), 2),
	            ])->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => [
                    'from' => $from,
                    'to' => $to,
                    'level_id' => $levelId,
                    'grade_id' => $gradeId,
                    'subject_id' => $subjectId,
                    'topic_id' => $topicId,
                ],
                'kpis' => [
                    'total_quizzes' => $totalQuizzes,
                    'quizzes_created_in_range' => $quizzesCreatedInRange,
                    'approved_quizzes' => (int) ($statusRow->approved ?? 0),
                    'draft_quizzes' => (int) ($statusRow->drafts ?? 0),
                    'paid_quizzes' => (int) ($statusRow->paid ?? 0),
                    'institutional_quizzes' => (int) ($statusRow->institutional ?? 0),
                    'total_questions' => $totalQuestions,
                    'questions_created_in_range' => $questionsCreatedInRange,
                    'attempts' => (int) ($attemptRow->attempts ?? 0),
                    'unique_users' => (int) ($attemptRow->unique_users ?? 0),
                    'avg_score' => round((float) ($attemptRow->avg_score ?? 0), 2),
                    'avg_time_seconds' => (int) round((float) ($attemptRow->avg_time ?? 0)),
                    'points_earned' => round((float) ($attemptRow->points_earned ?? 0), 2),
                    'paid_attempts' => (int) ($attemptRow->paid_attempts ?? 0),
                    'institution_attempts' => (int) ($attemptRow->institution_attempts ?? 0),
                    'likes' => $likesInRange,
                    'payments' => (int) ($txRow->payments ?? 0),
                    'gross_revenue' => round((float) ($txRow->gross ?? 0), 2),
                    'platform_share' => round((float) ($txRow->platform_share ?? 0), 2),
                    'quiz_master_share' => round((float) ($txRow->quiz_master_share ?? 0), 2),
                    'affiliate_share' => round((float) ($txRow->affiliate_share ?? 0), 2),
                ],
                'series' => $series,
                'top' => [
                    'by_attempts' => $topByAttempts,
                    'by_likes' => $topByLikes,
                    'by_revenue' => $topByRevenue,
                ],
	                'breakdown' => [
	                    'topics' => $breakdownTopic,
	                    'subjects' => $breakdownSubject,
	                    'grades' => $breakdownGrade,
	                    'levels' => $breakdownLevel,
	                ],
	            ],
	        ]);
    }
}
