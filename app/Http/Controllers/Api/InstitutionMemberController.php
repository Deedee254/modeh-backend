<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionAssignment;

class InstitutionMemberController extends Controller
{
    public function index(Request $request, Institution $institution)
    {
        $user = $request->user();
        // Only institution managers can list full members
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);

        $role = $request->input('role', null); // optional: quizee | quiz-master | member
        $levelId = $request->input('level_id', null);
        $gradeId = $request->input('grade_id', null);

        // Build a query on users that belong to this institution and optionally filter by pivot role
        $query = \App\Models\User::whereHas('institutions', function ($q) use ($institution, $role) {
            $q->where('institutions.id', $institution->id);
            if ($role) {
                $q->where('institution_user.role', $role);
            }
        });

        // Apply taxonomy filters (level_id, grade_id) by checking either quizee or quiz-master profiles
        if ($levelId || $gradeId) {
            $query->where(function ($q) use ($levelId, $gradeId) {
                $q->whereHas('quizeeProfile', function ($qq) use ($levelId, $gradeId) {
                    if ($levelId) $qq->where('level_id', $levelId);
                    if ($gradeId) $qq->where('grade_id', $gradeId);
                });
                $q->orWhereHas('quizMasterProfile', function ($qq) use ($levelId, $gradeId) {
                    if ($levelId) $qq->where('level_id', $levelId);
                    if ($gradeId) $qq->where('grade_id', $gradeId);
                });
            });
        }

        // Eager-load the pivot for this institution and profiles
        $query->with(['institutions' => function ($q) use ($institution) { $q->where('institutions.id', $institution->id); }, 'quizMasterProfile', 'quizeeProfile']);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $members = collect($paginator->items())->map(function ($u) use ($institution) {
            // pivot info for this institution is available under institutions relation (filtered)
            $inst = $u->institutions && count($u->institutions) ? $u->institutions[0] : null;
            $pivotRole = $inst && isset($inst->pivot) ? ($inst->pivot->role ?? null) : null;
            $pivotStatus = $inst && isset($inst->pivot) ? ($inst->pivot->status ?? null) : null;

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $pivotRole,
                'status' => $pivotStatus,
                'profile' => [
                    'quizee' => $u->quizeeProfile ? ['level_id' => $u->quizeeProfile->level_id ?? null, 'grade_id' => $u->quizeeProfile->grade_id ?? null] : null,
                    'quiz_master' => $u->quizMasterProfile ? ['level_id' => $u->quizMasterProfile->level_id ?? null, 'grade_id' => $u->quizMasterProfile->grade_id ?? null] : null,
                ]
            ];
        });

        return response()->json([
            'ok' => true,
            'members' => $members,
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ]
        ]);
    }

    public function requests(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $perPage = (int) $request->input('per_page', 10);
        $page = (int) $request->input('page', 1);
        // taxonomy filters (same as index)
        $levelId = $request->input('level_id', null);
        $gradeId = $request->input('grade_id', null);

        // collect user ids from profile-matched quizmasters and quizees, applying taxonomy filters when present
        $qmQuery = $institution->profileQuizMasters();
        if ($levelId) $qmQuery->where('level_id', $levelId);
        if ($gradeId) $qmQuery->where('grade_id', $gradeId);
        $qm = $qmQuery->pluck('user_id')->filter()->unique()->values()->toArray();

        $qzQuery = $institution->profileQuizees();
        if ($levelId) $qzQuery->where('level_id', $levelId);
        if ($gradeId) $qzQuery->where('grade_id', $gradeId);
        $qz = $qzQuery->pluck('user_id')->filter()->unique()->values()->toArray();
        $userIds = array_values(array_unique(array_merge($qm, $qz)));

        if (empty($userIds)) {
            return response()->json(['ok' => true, 'requests' => [], 'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => 0]]);
        }

        // exclude those already in pivot
        $existing = \DB::table('institution_user')->where('institution_id', $institution->id)->whereIn('user_id', $userIds)->pluck('user_id')->toArray();
        $pendingIds = array_values(array_diff($userIds, $existing));

        $query = \App\Models\User::whereIn('id', $pendingIds);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $pending = collect($paginator->items())->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'global_role' => $u->role ?? null,
            ];
        });

        return response()->json(['ok' => true, 'requests' => $pending, 'meta' => [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]]);
    }

    public function accept(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $data = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $u = User::find($data['user_id']);
        if (!$u) return response()->json(['ok' => false, 'message' => 'User not found'], 404);

        // Determine pivot role based on user's global role
        $pivotRole = 'member';
        if ($u->role === 'quizee') $pivotRole = 'quizee';
        if ($u->role === 'quiz-master') $pivotRole = 'quiz-master';

        // Seat enforcement: check active institution subscription for seat limit
        $activeSub = Subscription::where('owner_type', \App\Models\Institution::class)
            ->where('owner_id', $institution->id)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();

        if ($activeSub && $activeSub->package) {
            $available = $activeSub->availableSeats();
            if (!is_null($available) && $available <= 0) {
                return response()->json(['ok' => false, 'message' => 'Seat limit reached for this institution package'], 422);
            }
        }

        // Attach or update pivot
        $existing = \DB::table('institution_user')->where('institution_id', $institution->id)->where('user_id', $u->id)->first();
        if ($existing) {
            \DB::table('institution_user')->where('id', $existing->id)->update(['role' => $pivotRole, 'status' => 'active', 'updated_at' => now()]);
        } else {
            \DB::table('institution_user')->insert([
                'institution_id' => $institution->id,
                'user_id' => $u->id,
                'role' => $pivotRole,
                'status' => 'active',
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // If institution has an active subscription, attempt to assign a seat to this user
        if ($activeSub && $activeSub->package) {
            try {
                $assignment = $activeSub->assignUser($u->id, $user->id);
                if (!$assignment) {
                    // rollback pivot change
                    // mark the pivot back to pending or delete (we'll mark pending)
                    \DB::table('institution_user')->where('institution_id', $institution->id)->where('user_id', $u->id)->update(['status' => 'pending', 'updated_at' => now()]);
                    return response()->json(['ok' => false, 'message' => 'Failed to assign subscription seat: limit reached'], 422);
                }
            } catch (\Throwable $e) {
                // best-effort: revert pivot and surface error
                \DB::table('institution_user')->where('institution_id', $institution->id)->where('user_id', $u->id)->update(['status' => 'pending', 'updated_at' => now()]);
                return response()->json(['ok' => false, 'message' => 'Failed to assign subscription seat'], 500);
            }
        }

        return response()->json(['ok' => true, 'message' => 'User accepted into institution']);
    }

    public function remove(Request $request, Institution $institution, $userId)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        \DB::table('institution_user')->where('institution_id', $institution->id)->where('user_id', $userId)->delete();
        return response()->json(['ok' => true, 'message' => 'User removed from institution']);
    }

    /**
     * Return the active subscription for the institution (if any), available seats and current assignments
     */
    public function subscription(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $activeSub = Subscription::where('owner_type', \App\Models\Institution::class)
            ->where('owner_id', $institution->id)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();

        if (!$activeSub) {
            return response()->json(['ok' => true, 'subscription' => null, 'available_seats' => null, 'assignments' => []]);
        }

        $assignments = $activeSub->assignments()->whereNull('revoked_at')->with(['user', 'assignedBy'])->get()->map(function ($a) {
            return [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'user_name' => $a->user ? $a->user->name : null,
                'user_email' => $a->user ? $a->user->email : null,
                'assigned_by' => $a->assignedBy ? $a->assignedBy->name : null,
                'assigned_at' => $a->assigned_at ? $a->assigned_at->toDateTimeString() : null,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'subscription' => $activeSub,
            'available_seats' => $activeSub->availableSeats(),
            'assignments' => $assignments,
        ]);
    }

    /**
     * Revoke an assignment (free up a seat) for a user on the institution's active subscription.
     */
    public function revokeAssignment(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $data = $request->validate([
            'user_id' => 'required|integer',
        ]);

        $activeSub = Subscription::where('owner_type', \App\Models\Institution::class)
            ->where('owner_id', $institution->id)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();

        if (!$activeSub) return response()->json(['ok' => false, 'message' => 'No active subscription found'], 404);

        $assignment = SubscriptionAssignment::where('subscription_id', $activeSub->id)
            ->where('user_id', $data['user_id'])
            ->whereNull('revoked_at')
            ->first();

        if (!$assignment) {
            return response()->json(['ok' => false, 'message' => 'Assignment not found'], 404);
        }

        $assignment->revoked_at = now();
        $assignment->save();

        // Optionally mark the pivot as removed so the member no longer counts as active
        try {
            \DB::table('institution_user')->where('institution_id', $institution->id)->where('user_id', $data['user_id'])->update(['status' => 'removed', 'updated_at' => now()]);
        } catch (\Throwable $_) {}

        return response()->json(['ok' => true, 'message' => 'Assignment revoked']);
    }
}
