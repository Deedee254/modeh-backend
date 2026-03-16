<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminBattleController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $query = Battle::query()->with([
            'initiator.user:id,name,email',
            'opponent.user:id,name,email',
            'winner.user:id,name,email',
        ]);

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from') . ' 00:00:00');
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to') . ' 23:59:59');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', '%' . $search . '%')
                    ->orWhereHas('initiator.user', function ($u) use ($search) {
                        $u->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('opponent.user', function ($u) use ($search) {
                        $u->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
            });
        }

        $total = $query->count();
        $perPage = (int) $request->input('limit', 50);
        $page = (int) $request->input('page', 1);

        $battles = $query
            ->orderBy('created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function (Battle $b) {
                $initiatorUser = $b->initiator?->user;
                $opponentUser = $b->opponent?->user;
                $winnerUser = $b->winner?->user;

                return [
                    'id' => $b->id,
                    'uuid' => $b->uuid,
                    'status' => $b->status,
                    'created_at' => $b->created_at,
                    'completed_at' => $b->completed_at,
                    'subscription_type' => $b->subscription_type,
                    'one_off_price' => (float) ($b->one_off_price ?? 0),
                    'initiator' => [
                        'id' => $b->initiator_id,
                        'name' => $initiatorUser?->name ?? trim(($b->initiator?->first_name ?? '') . ' ' . ($b->initiator?->last_name ?? '')) ?: null,
                        'email' => $initiatorUser?->email,
                        'points' => (int) ($b->initiator_points ?? 0),
                    ],
                    'opponent' => [
                        'id' => $b->opponent_id,
                        'name' => $opponentUser?->name ?? trim(($b->opponent?->first_name ?? '') . ' ' . ($b->opponent?->last_name ?? '')) ?: null,
                        'email' => $opponentUser?->email,
                        'points' => (int) ($b->opponent_points ?? 0),
                    ],
                    'winner' => [
                        'id' => $b->winner_id,
                        'name' => $winnerUser?->name ?? null,
                        'email' => $winnerUser?->email ?? null,
                    ],
                ];
            });

        return response()->json([
            'ok' => true,
            'battles' => $battles,
            'total' => $total,
            'page' => $page,
            'limit' => $perPage,
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user || !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
        }

        $battle = DB::table('battles as b')
            ->where('b.id', (int) $id)
            ->first();

        if (!$battle) {
            return response()->json(['ok' => false, 'message' => 'Battle not found'], 404);
        }

        $userIds = array_values(array_filter([
            (int) ($battle->initiator_id ?? 0),
            (int) ($battle->opponent_id ?? 0),
            (int) ($battle->winner_id ?? 0),
        ]));

        $users = DB::table('users')
            ->whereIn('id', $userIds)
            ->selectRaw('id, name, email, COALESCE(avatar_url, social_avatar) as avatar')
            ->get()
            ->keyBy('id');

        $questions = DB::table('battle_questions as bq')
            ->join('questions as q', 'q.id', '=', 'bq.question_id')
            ->where('bq.battle_id', (int) $id)
            ->orderBy('bq.position', 'asc')
            ->orderBy('bq.id', 'asc')
            ->get([
                'bq.position as position',
                'q.id as question_id',
                'q.body as body',
                'q.type as type',
                'q.options as options',
                'q.answers as answers',
                'q.explanation as explanation',
            ]);

        $subs = DB::table('battle_submissions as s')
            ->where('s.battle_id', (int) $id)
            ->orderBy('s.created_at', 'asc')
            ->get([
                's.user_id',
                's.question_id',
                's.selected',
                's.time_taken',
                's.correct_flag',
                's.created_at',
            ]);

        $subsByUserQuestion = [];
        foreach ($subs as $s) {
            $subsByUserQuestion[(int) $s->user_id . ':' . (int) $s->question_id] = $s;
        }

        $initiatorId = (int) ($battle->initiator_id ?? 0);
        $opponentId = (int) ($battle->opponent_id ?? 0);

        $initiatorCorrect = 0;
        $opponentCorrect = 0;
        $initiatorTime = 0.0;
        $opponentTime = 0.0;
        $initiatorAnswered = 0;
        $opponentAnswered = 0;

        $outQuestions = [];
        foreach ($questions as $q) {
            $initSub = $subsByUserQuestion[$initiatorId . ':' . (int) $q->question_id] ?? null;
            $oppSub = $subsByUserQuestion[$opponentId . ':' . (int) $q->question_id] ?? null;

            $initCorrect = $initSub ? (bool) $initSub->correct_flag : null;
            $oppCorrect = $oppSub ? (bool) $oppSub->correct_flag : null;

            if ($initSub) {
                $initiatorAnswered++;
                if ($initCorrect) $initiatorCorrect++;
                $initiatorTime += (float) ($initSub->time_taken ?? 0);
            }
            if ($oppSub) {
                $opponentAnswered++;
                if ($oppCorrect) $opponentCorrect++;
                $opponentTime += (float) ($oppSub->time_taken ?? 0);
            }

            $outQuestions[] = [
                'position' => (int) ($q->position ?? 0),
                'question_id' => (int) $q->question_id,
                'type' => $q->type,
                'body' => $q->body,
                'initiator' => $initSub ? [
                    'selected' => $initSub->selected,
                    'time_taken' => (float) ($initSub->time_taken ?? 0),
                    'correct' => $initCorrect,
                    'submitted_at' => $initSub->created_at,
                ] : null,
                'opponent' => $oppSub ? [
                    'selected' => $oppSub->selected,
                    'time_taken' => (float) ($oppSub->time_taken ?? 0),
                    'correct' => $oppCorrect,
                    'submitted_at' => $oppSub->created_at,
                ] : null,
            ];
        }

        $totalQuestions = count($outQuestions);
        $score = [
            'initiator_points' => (int) ($battle->initiator_points ?? $initiatorCorrect),
            'opponent_points' => (int) ($battle->opponent_points ?? $opponentCorrect),
        ];

        return response()->json([
            'ok' => true,
            'data' => [
                'battle' => [
                    'id' => (int) $battle->id,
                    'uuid' => $battle->uuid,
                    'status' => $battle->status,
                    'subscription_type' => $battle->subscription_type ?? null,
                    'one_off_price' => (float) ($battle->one_off_price ?? 0),
                    'rounds_completed' => (int) ($battle->rounds_completed ?? 0),
                    'created_at' => $battle->created_at,
                    'completed_at' => $battle->completed_at,
                    'initiator_id' => $initiatorId,
                    'opponent_id' => $opponentId,
                    'winner_id' => $battle->winner_id ? (int) $battle->winner_id : null,
                ],
                'players' => [
                    'initiator' => [
                        'id' => $initiatorId,
                        'name' => $users->get($initiatorId)?->name,
                        'email' => $users->get($initiatorId)?->email,
                        'avatar' => $users->get($initiatorId)?->avatar,
                    ],
                    'opponent' => [
                        'id' => $opponentId,
                        'name' => $users->get($opponentId)?->name,
                        'email' => $users->get($opponentId)?->email,
                        'avatar' => $users->get($opponentId)?->avatar,
                    ],
                    'winner' => $battle->winner_id ? [
                        'id' => (int) $battle->winner_id,
                        'name' => $users->get((int) $battle->winner_id)?->name,
                        'email' => $users->get((int) $battle->winner_id)?->email,
                        'avatar' => $users->get((int) $battle->winner_id)?->avatar,
                    ] : null,
                ],
                'kpis' => [
                    'questions' => $totalQuestions,
                    'score' => $score,
                    'initiator' => [
                        'answered' => $initiatorAnswered,
                        'correct' => $initiatorCorrect,
                        'accuracy' => $initiatorAnswered > 0 ? round(($initiatorCorrect / $initiatorAnswered) * 100, 2) : 0,
                        'avg_time' => $initiatorAnswered > 0 ? round($initiatorTime / $initiatorAnswered, 2) : 0,
                    ],
                    'opponent' => [
                        'answered' => $opponentAnswered,
                        'correct' => $opponentCorrect,
                        'accuracy' => $opponentAnswered > 0 ? round(($opponentCorrect / $opponentAnswered) * 100, 2) : 0,
                        'avg_time' => $opponentAnswered > 0 ? round($opponentTime / $opponentAnswered, 2) : 0,
                    ],
                ],
                'questions' => $outQuestions,
            ],
        ]);
    }
}
