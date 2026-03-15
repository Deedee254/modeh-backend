<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use Illuminate\Http\Request;

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
}

