<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminQuizInsightsController extends Controller
{
    private function requireAdmin()
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }
        return null;
    }

    public function insightsBySlug(Request $request, string $slug)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $quiz = Quiz::query()
            ->where('slug', $slug)
            ->with([
                'topic:id,name',
                'subject:id,name',
                'grade:id,name',
                'level:id,name',
                'author:id,name,email',
                'quizMaster:id,user_id',
            ])
            ->first();

        if (!$quiz) {
            return response()->json(['ok' => false, 'message' => 'Quiz not found'], 404);
        }

        // Also enforce the same policy used by QuizAnalyticsController (admin will pass).
        $this->authorize('viewAnalytics', $quiz);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : now()->toDateString();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $attemptBase = DB::table('quiz_attempts as a')
            ->where('a.quiz_id', $quiz->id)
            ->whereBetween('a.created_at', [$fromTs, $toTs]);

        $attemptRow = (clone $attemptBase)
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('COUNT(DISTINCT a.user_id) as unique_users')
            ->selectRaw('SUM(CASE WHEN a.score IS NOT NULL THEN 1 ELSE 0 END) as completions')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('AVG(a.total_time_seconds) as avg_time')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->selectRaw('SUM(CASE WHEN COALESCE(a.paid_for,0) = 1 THEN 1 ELSE 0 END) as paid_attempts')
            ->selectRaw('SUM(CASE WHEN COALESCE(a.institution_access,0) = 1 THEN 1 ELSE 0 END) as institution_attempts')
            ->first();

        $likesBase = DB::table('quiz_likes as l')
            ->where('l.quiz_id', $quiz->id)
            ->whereBetween('l.created_at', [$fromTs, $toTs]);
        $likesInRange = (int) (clone $likesBase)->count();

        $txBase = DB::table('transactions as t')
            ->where('t.quiz_id', $quiz->id)
            ->where('t.type', 'payment')
            ->whereIn('t.status', ['confirmed', 'completed'])
            ->whereBetween('t.created_at', [$fromTs, $toTs]);

        $txRow = (clone $txBase)
            ->selectRaw('COUNT(*) as payments')
            ->selectRaw('SUM(COALESCE(t.amount,0)) as gross')
            ->selectRaw('SUM(COALESCE(t.platform_share,0)) as platform_share')
            ->selectRaw('SUM(COALESCE(t.`quiz-master_share`,0)) as quiz_master_share')
            ->selectRaw('SUM(COALESCE(t.affiliate_share,0)) as affiliate_share')
            ->first();

        // Date series (range)
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $aRows = (clone $attemptBase)
            ->selectRaw('DATE(a.created_at) as date')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('AVG(a.score) as avg_score')
            ->selectRaw('SUM(COALESCE(a.points_earned,0)) as points_earned')
            ->groupBy(DB::raw('DATE(a.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $aByDate = [];
        foreach ($aRows as $r) $aByDate[$r->date] = $r;

        $lRows = (clone $likesBase)
            ->selectRaw('DATE(l.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(l.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $lByDate = [];
        foreach ($lRows as $r) $lByDate[$r->date] = (int) ($r->value ?? 0);

        $rRows = (clone $txBase)
            ->selectRaw('DATE(t.created_at) as date')
            ->selectRaw('SUM(COALESCE(t.amount,0)) as gross')
            ->selectRaw('SUM(COALESCE(t.platform_share,0)) as platform_share')
            ->selectRaw('SUM(COALESCE(t.`quiz-master_share`,0)) as quiz_master_share')
            ->selectRaw('SUM(COALESCE(t.affiliate_share,0)) as affiliate_share')
            ->groupBy(DB::raw('DATE(t.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $revByDate = [];
        foreach ($rRows as $r) $revByDate[$r->date] = $r;

        $series = [
            'attempts' => [],
            'avg_score' => [],
            'points_earned' => [],
            'likes' => [],
            'revenue' => [],
            'platform_share' => [],
            'quiz_master_share' => [],
            'affiliate_share' => [],
        ];
        foreach ($dates as $date) {
            $ar = $aByDate[$date] ?? null;
            $rr = $revByDate[$date] ?? null;
            $series['attempts'][] = ['date' => $date, 'value' => (int) ($ar?->attempts ?? 0)];
            $series['avg_score'][] = ['date' => $date, 'value' => (float) ($ar?->avg_score ?? 0)];
            $series['points_earned'][] = ['date' => $date, 'value' => (float) ($ar?->points_earned ?? 0)];
            $series['likes'][] = ['date' => $date, 'value' => (int) ($lByDate[$date] ?? 0)];
            $series['revenue'][] = ['date' => $date, 'value' => (float) ($rr?->gross ?? 0)];
            $series['platform_share'][] = ['date' => $date, 'value' => (float) ($rr?->platform_share ?? 0)];
            $series['quiz_master_share'][] = ['date' => $date, 'value' => (float) ($rr?->quiz_master_share ?? 0)];
            $series['affiliate_share'][] = ['date' => $date, 'value' => (float) ($rr?->affiliate_share ?? 0)];
        }

        // Reuse the existing quiz analytics output (per-question, distribution, etc.)
        $perf = app(\App\Http\Controllers\Api\QuizAnalyticsController::class)->show($request, $quiz)->getData(true);

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'quiz' => [
                    'id' => (int) $quiz->id,
                    'slug' => $quiz->slug,
                    'title' => $quiz->title,
                    'description' => $quiz->description,
                    'is_paid' => (bool) ($quiz->is_paid ?? false),
                    'one_off_price' => (float) ($quiz->one_off_price ?? 0),
                    'is_institutional' => (bool) ($quiz->is_institutional ?? false),
                    'is_approved' => (bool) ($quiz->is_approved ?? false),
                    'is_draft' => (bool) ($quiz->is_draft ?? false),
                    'created_at' => $quiz->created_at,
                    'topic' => $quiz->topic?->name,
                    'subject' => $quiz->subject?->name,
                    'grade' => $quiz->grade?->name,
                    'level' => $quiz->level?->name,
                    'author' => $quiz->author ? [
                        'id' => (int) ($quiz->author->id ?? 0),
                        'name' => $quiz->author->name,
                        'email' => $quiz->author->email,
                    ] : null,
                ],
                'kpis' => [
                    'attempts' => (int) ($attemptRow->attempts ?? 0),
                    'unique_users' => (int) ($attemptRow->unique_users ?? 0),
                    'completions' => (int) ($attemptRow->completions ?? 0),
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
                'performance' => $perf,
            ],
        ]);
    }
}

