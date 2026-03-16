<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminTournamentAnalyticsController extends Controller
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
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : now()->toDateString();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $totalTournaments = (int) DB::table('tournaments')->count();
        $createdInRange = (int) DB::table('tournaments')->whereBetween('created_at', [$fromTs, $toTs])->count();

        $statusCounts = DB::table('tournaments')
            ->selectRaw("SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming")
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->first();

        $registrationsInRange = (int) DB::table('tournament_participants')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();
        $uniqueRegistrantsInRange = (int) DB::table('tournament_participants')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->distinct('user_id')
            ->count('user_id');

        $completedParticipantsInRange = (int) DB::table('tournament_participants')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->whereNotNull('completed_at')
            ->count();
        $completionRate = $registrationsInRange > 0 ? ($completedParticipantsInRange / $registrationsInRange) * 100 : 0;

        $battlesInRange = (int) DB::table('tournament_battles')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->count();

        // Date series
        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $tRows = DB::table('tournaments')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $tByDate = [];
        foreach ($tRows as $r) $tByDate[$r->date] = (int) $r->value;

        $pRows = DB::table('tournament_participants')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $pByDate = [];
        foreach ($pRows as $r) $pByDate[$r->date] = (int) $r->value;

        $bRows = DB::table('tournament_battles')
            ->whereBetween('created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $bByDate = [];
        foreach ($bRows as $r) $bByDate[$r->date] = (int) $r->value;

        $series = [
            'tournaments_created' => [],
            'registrations' => [],
            'battles' => [],
        ];
        foreach ($dates as $date) {
            $series['tournaments_created'][] = ['date' => $date, 'value' => (int) ($tByDate[$date] ?? 0)];
            $series['registrations'][] = ['date' => $date, 'value' => (int) ($pByDate[$date] ?? 0)];
            $series['battles'][] = ['date' => $date, 'value' => (int) ($bByDate[$date] ?? 0)];
        }

        $topTournaments = DB::table('tournaments as t')
            ->leftJoin('tournament_participants as tp', 'tp.tournament_id', '=', 't.id')
            ->whereBetween('t.created_at', [$fromTs, $toTs])
            ->selectRaw('t.id, MAX(t.name) as name, MAX(t.status) as status, MAX(t.start_date) as start_date')
            ->selectRaw('COUNT(tp.id) as participants')
            ->groupBy('t.id')
            ->orderByDesc('participants')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'status' => $r->status,
                    'start_date' => $r->start_date,
                    'participants' => (int) ($r->participants ?? 0),
                ];
            })->values();

        $topSubjects = DB::table('tournaments as t')
            ->join('subjects as s', 's.id', '=', 't.subject_id')
            ->whereBetween('t.created_at', [$fromTs, $toTs])
            ->selectRaw('t.subject_id as id, MAX(s.name) as name, COUNT(*) as tournaments')
            ->groupBy('t.subject_id')
            ->orderByDesc('tournaments')
            ->limit(8)
            ->get()
            ->map(fn($r) => ['id' => (int) $r->id, 'name' => $r->name, 'tournaments' => (int) $r->tournaments])
            ->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'kpis' => [
                    'total_tournaments' => $totalTournaments,
                    'created_in_range' => $createdInRange,
                    'upcoming' => (int) ($statusCounts->upcoming ?? 0),
                    'active' => (int) ($statusCounts->active ?? 0),
                    'completed' => (int) ($statusCounts->completed ?? 0),
                    'registrations_in_range' => $registrationsInRange,
                    'unique_registrants_in_range' => $uniqueRegistrantsInRange,
                    'completed_participants_in_range' => $completedParticipantsInRange,
                    'completion_rate' => round((float) $completionRate, 2),
                    'battles_in_range' => $battlesInRange,
                ],
                'series' => $series,
                'top' => [
                    'tournaments_by_participants' => $topTournaments,
                    'subjects' => $topSubjects,
                ],
            ],
        ]);
    }

    public function insights(Request $request, $id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->toDateString() : now()->toDateString();
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->toDateString() : Carbon::parse($to)->subDays(29)->toDateString();
        if ($from > $to) [$from, $to] = [$to, $from];
        $fromTs = $from . ' 00:00:00';
        $toTs = $to . ' 23:59:59';

        $t = DB::table('tournaments as t')
            ->leftJoin('subjects as s', 's.id', '=', 't.subject_id')
            ->leftJoin('topics as tp', 'tp.id', '=', 't.topic_id')
            ->leftJoin('grades as g', 'g.id', '=', 't.grade_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->leftJoin('users as w', 'w.id', '=', 't.winner_id')
            ->where('t.id', (int) $id)
            ->first([
                't.id',
                't.name',
                't.description',
                't.status',
                't.start_date',
                't.end_date',
                't.prize_pool',
                't.entry_fee',
                't.max_participants',
                't.created_at',
                't.created_by',
                't.winner_id',
                's.name as subject_name',
                'tp.name as topic_name',
                'g.name as grade_name',
                'u.name as created_by_name',
                'u.email as created_by_email',
                DB::raw('COALESCE(u.avatar_url, u.social_avatar) as created_by_avatar'),
                'w.name as winner_name',
                'w.email as winner_email',
                DB::raw('COALESCE(w.avatar_url, w.social_avatar) as winner_avatar'),
            ]);

        if (!$t) return response()->json(['ok' => false, 'message' => 'Tournament not found'], 404);

        $participantsQ = DB::table('tournament_participants as p')
            ->where('p.tournament_id', (int) $id);

        $participantsInRangeQ = (clone $participantsQ)->whereBetween('p.created_at', [$fromTs, $toTs]);

        $participantCount = (int) (clone $participantsQ)->count();
        $registrationsInRange = (int) (clone $participantsInRangeQ)->count();
        $uniqueRegistrantsInRange = (int) (clone $participantsInRangeQ)->distinct('p.user_id')->count('p.user_id');
        $completedInRange = (int) (clone $participantsInRangeQ)->whereNotNull('p.completed_at')->count();
        $completionRate = $registrationsInRange > 0 ? ($completedInRange / $registrationsInRange) * 100 : 0;

        $scoreStats = (clone $participantsQ)
            ->selectRaw('AVG(p.score) as avg_score')
            ->selectRaw('MAX(p.score) as max_score')
            ->selectRaw('MIN(p.score) as min_score')
            ->first();

        $battlesQ = DB::table('tournament_battles as b')
            ->where('b.tournament_id', (int) $id);
        $battlesInRange = (int) (clone $battlesQ)->whereBetween('b.created_at', [$fromTs, $toTs])->count();

        $fromDt = Carbon::parse($from);
        $toDt = Carbon::parse($to);
        $dates = [];
        for ($d = $fromDt->copy(); $d->lte($toDt); $d->addDay()) $dates[] = $d->toDateString();

        $regRows = (clone $participantsInRangeQ)
            ->selectRaw('DATE(p.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(p.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $regByDate = [];
        foreach ($regRows as $r) $regByDate[$r->date] = (int) ($r->value ?? 0);

        $compRows = (clone $participantsInRangeQ)
            ->whereNotNull('p.completed_at')
            ->selectRaw('DATE(p.completed_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(p.completed_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $compByDate = [];
        foreach ($compRows as $r) $compByDate[$r->date] = (int) ($r->value ?? 0);

        $battleRows = (clone $battlesQ)
            ->whereBetween('b.created_at', [$fromTs, $toTs])
            ->selectRaw('DATE(b.created_at) as date, COUNT(*) as value')
            ->groupBy(DB::raw('DATE(b.created_at)'))
            ->orderBy('date', 'asc')
            ->get();
        $battleByDate = [];
        foreach ($battleRows as $r) $battleByDate[$r->date] = (int) ($r->value ?? 0);

        $series = [
            'registrations' => [],
            'completions' => [],
            'battles' => [],
        ];
        foreach ($dates as $date) {
            $series['registrations'][] = ['date' => $date, 'value' => (int) ($regByDate[$date] ?? 0)];
            $series['completions'][] = ['date' => $date, 'value' => (int) ($compByDate[$date] ?? 0)];
            $series['battles'][] = ['date' => $date, 'value' => (int) ($battleByDate[$date] ?? 0)];
        }

        $topParticipants = DB::table('tournament_participants as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.tournament_id', (int) $id)
            ->selectRaw('p.user_id as user_id')
            ->selectRaw('MAX(u.name) as name')
            ->selectRaw('MAX(u.email) as email')
            ->selectRaw('MAX(COALESCE(u.avatar_url, u.social_avatar)) as avatar')
            ->selectRaw('MAX(p.score) as score')
            ->selectRaw('MAX(p.rank) as rank')
            ->groupBy('p.user_id')
            ->orderByDesc('score')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int) $r->user_id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'avatar' => $r->avatar,
                    'score' => (float) ($r->score ?? 0),
                    'rank' => $r->rank !== null ? (int) $r->rank : null,
                ];
            })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'filters' => ['from' => $from, 'to' => $to],
                'tournament' => [
                    'id' => (int) $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'status' => $t->status,
                    'start_date' => $t->start_date,
                    'end_date' => $t->end_date,
                    'prize_pool' => (float) ($t->prize_pool ?? 0),
                    'entry_fee' => (float) ($t->entry_fee ?? 0),
                    'max_participants' => $t->max_participants !== null ? (int) $t->max_participants : null,
                    'created_at' => $t->created_at,
                    'subject' => $t->subject_name,
                    'topic' => $t->topic_name,
                    'grade' => $t->grade_name,
                    'created_by' => [
                        'id' => (int) ($t->created_by ?? 0),
                        'name' => $t->created_by_name,
                        'email' => $t->created_by_email,
                        'avatar' => $t->created_by_avatar,
                    ],
                    'winner' => $t->winner_id ? [
                        'id' => (int) $t->winner_id,
                        'name' => $t->winner_name,
                        'email' => $t->winner_email,
                        'avatar' => $t->winner_avatar,
                    ] : null,
                ],
                'kpis' => [
                    'participants_total' => $participantCount,
                    'registrations_in_range' => $registrationsInRange,
                    'unique_registrants_in_range' => $uniqueRegistrantsInRange,
                    'completed_in_range' => $completedInRange,
                    'completion_rate' => round((float) $completionRate, 2),
                    'avg_score' => round((float) ($scoreStats->avg_score ?? 0), 2),
                    'min_score' => round((float) ($scoreStats->min_score ?? 0), 2),
                    'max_score' => round((float) ($scoreStats->max_score ?? 0), 2),
                    'battles_in_range' => $battlesInRange,
                ],
                'series' => $series,
                'top' => [
                    'participants' => $topParticipants,
                ],
            ],
        ]);
    }
}
