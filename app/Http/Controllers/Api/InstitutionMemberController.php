<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionAssignment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
        $query = User::whereHas('institutions', function ($q) use ($institution, $role) {
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

        $query = User::whereIn('id', $pendingIds);
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
        $activeSub = Subscription::where('owner_type', Institution::class)
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

        $activeSub = Subscription::where('owner_type', Institution::class)
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

        $activeSub = Subscription::where('owner_type', Institution::class)
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

    /**
     * Send a direct invite to a user via email
     */
    public function invite(Request $request, Institution $institution)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'nullable|in:quizee,quiz-master',
            'expires_in_days' => 'nullable|integer|min:1|max:30'
        ]);

        $existingUser = User::where('email', $data['email'])->first();

        $existingInvite = DB::table('institution_user')
            ->where('institution_id', $institution->id)
            ->where('invited_email', $data['email'])
            ->where('invitation_status', 'invited')
            ->where('invitation_expires_at', '>', now())
            ->first();

        if ($existingInvite) {
            return response()->json([
                'ok' => false,
                'message' => 'User already invited'
            ], 422);
        }

        $activeSub = $institution->activeSubscription();
        if ($activeSub && $activeSub->package) {
            $available = $activeSub->availableSeats();
            if (!is_null($available) && $available <= 0) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No available seats'
                ], 422);
            }
        }

        $token = Str::random(32);
        $expiresAt = now()->addDays($data['expires_in_days'] ?? 14);
        $role = $data['role'] ?? 'member';

        if ($existingUser) {
            $existing = DB::table('institution_user')
                ->where('institution_id', $institution->id)
                ->where('user_id', $existingUser->id)
                ->first();

            if ($existing) {
                return response()->json([
                    'ok' => false,
                    'message' => 'User is already a member'
                ], 422);
            }

            DB::table('institution_user')->insert([
                'institution_id' => $institution->id,
                'user_id' => $existingUser->id,
                'role' => $role,
                'invitation_token' => $token,
                'invitation_expires_at' => $expiresAt,
                'invitation_status' => 'invited',
                'invited_email' => $data['email'],
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            DB::table('institution_user')->insert([
                'institution_id' => $institution->id,
                'user_id' => null,
                'role' => $role,
                'invitation_token' => $token,
                'invitation_expires_at' => $expiresAt,
                'invitation_status' => 'invited',
                'invited_email' => $data['email'],
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Generate a short-lived frontend token (ftoken) so recipients that get
        // the backend-sent email land on the frontend verification flow and can
        // do one-click verify+accept. Cache mapping like generateInviteToken().
        try {
            $frontend = env('FRONTEND_URL', config('app.url'));
            $ftoken = \Illuminate\Support\Str::random(48);
            $cacheKey = 'invite_frontend_token:' . $ftoken;
            $ttlMinutes = max(60, (int) round($expiresAt->diffInMinutes(now())));
            Cache::put($cacheKey, ['invitation_token' => $token, 'institution_id' => $institution->id], now()->addMinutes($ttlMinutes));

            // Send invitation email (passes ftoken so email contains frontend link)
            \Mail::to($data['email'])->send(
                new \App\Mail\InstitutionInvitationEmail(
                    $institution,
                    $data['email'],
                    $token,
                    $expiresAt,
                    $user,
                    $ftoken
                )
            );
        } catch (\Throwable $e) {
            \Log::error('Failed to send institution invitation email', [
                'email' => $data['email'],
                'institution_id' => $institution->id,
                'error' => $e->getMessage()
            ]);
            // Don't fail the invitation creation if email fails
        }

        return response()->json([
            'ok' => true,
            'message' => 'Invitation sent to ' . $data['email'],
            'invitation_token' => $token,
            'expires_at' => $expiresAt
        ], 201);
    }

    /**
     * Generate an invitation token without sending an email. Frontend may use this
     * to compose and send the invite via its preferred channel. Returns token and
     * a frontend URL that the inviter can include in emails.
     */
    public function generateInviteToken(Request $request, Institution $institution)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'nullable|in:quizee,quiz-master',
            'expires_in_days' => 'nullable|integer|min:1|max:30'
        ]);

        $existingUser = User::where('email', $data['email'])->first();

        $existingInvite = DB::table('institution_user')
            ->where('institution_id', $institution->id)
            ->where('invited_email', $data['email'])
            ->where('invitation_status', 'invited')
            ->where('invitation_expires_at', '>', now())
            ->first();

        if ($existingInvite) {
            return response()->json([
                'ok' => false,
                'message' => 'User already invited'
            ], 422);
        }

        $token = Str::random(32);
        $expiresAt = now()->addDays($data['expires_in_days'] ?? 14);
        $role = $data['role'] ?? 'member';

        if ($existingUser) {
            DB::table('institution_user')->insert([
                'institution_id' => $institution->id,
                'user_id' => $existingUser->id,
                'role' => $role,
                'invitation_token' => $token,
                'invitation_expires_at' => $expiresAt,
                'invitation_status' => 'invited',
                'invited_email' => $data['email'],
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        } else {
            DB::table('institution_user')->insert([
                'institution_id' => $institution->id,
                'user_id' => null,
                'role' => $role,
                'invitation_token' => $token,
                'invitation_expires_at' => $expiresAt,
                'invitation_status' => 'invited',
                'invited_email' => $data['email'],
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

    $frontend = env('FRONTEND_URL', config('app.url'));
    // Generate a short-lived frontend token (ftoken) so a single frontend URL
    // can carry a one-time token that maps back to this invitation. Store in cache
    // for the same duration as the invitation expiry.
    $ftoken = \Illuminate\Support\Str::random(48);
    $cacheKey = 'invite_frontend_token:' . $ftoken;
    $ttlMinutes = max(60, (int) round($expiresAt->diffInMinutes(now())));
    \Illuminate\Support\Facades\Cache::put($cacheKey, ['invitation_token' => $token, 'institution_id' => $institution->id], now()->addMinutes($ttlMinutes));

    // Use the email-verified flow on the frontend so recipients land on the verification page
    $inviteUrl = $frontend . '/email-verified?invite=' . $token . '&ftoken=' . $ftoken . '&email=' . urlencode($data['email']);

        return response()->json([
            'ok' => true,
            'message' => 'Invitation token generated',
            'invitation_token' => $token,
            'expires_at' => $expiresAt,
            'invite_url' => $inviteUrl,
        ], 201);
    }

    /**
     * Get invitation details by token
     */
    public function getInvitationDetails($token)
    {
        $invitation = DB::table('institution_user')
            ->where('invitation_token', $token)
            ->where('invitation_expires_at', '>', now())
            ->first();

        if (!$invitation) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        $institution = Institution::find($invitation->institution_id);

        return response()->json([
            'ok' => true,
            'invitation' => [
                'institution_id' => $institution->id,
                'institution_name' => $institution->name,
                'institution_slug' => $institution->slug,
                'role' => $invitation->role,
                'expires_at' => $invitation->invitation_expires_at
            ]
        ]);
    }

    /**
     * Accept a direct invitation
     */
    public function acceptInvitation(Request $request, Institution $institution, $token)
    {
        $user = $request->user();

        $invitation = DB::table('institution_user')
            ->where('institution_id', $institution->id)
            ->where('invitation_token', $token)
            ->first();

        if (!$invitation) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid invitation'
            ], 404);
        }

        if ($invitation->invitation_expires_at && now()->isAfter($invitation->invitation_expires_at)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invitation expired'
            ], 422);
        }

        DB::table('institution_user')
            ->where('id', $invitation->id)
            ->update([
                'user_id' => $user->id,
                'invitation_status' => 'active',
                'status' => 'active',
                'invitation_token' => null,
                'invitation_expires_at' => null,
                'updated_at' => now()
            ]);

        $activeSub = $institution->activeSubscription();
        if ($activeSub && $activeSub->package) {
            try {
                $activeSub->assignUser($user->id, $user->id);
            } catch (\Throwable $e) {
                \Log::error('Failed to assign seat', ['error' => $e->getMessage()]);
            }
        }

        // Log acceptance
        \Log::info('Invitation accepted', ['institution_id' => $institution->id, 'user_id' => $user->id, 'invited_email' => $invitation->invited_email]);

        // Notify institution managers that a new user joined via invitation
        try {
            $managers = $institution->users()->wherePivot('role', 'institution-manager')->get();
            foreach ($managers as $m) {
                try {
                    $m->notify(new \App\Notifications\InvitationAccepted($institution, $user));
                } catch (\Throwable $_) {
                    // continue even if notification fails for one manager
                }
            }
        } catch (\Throwable $_) {
            // ignore notification failures
        }

        return response()->json([
            'ok' => true,
            'message' => 'Successfully joined ' . $institution->name
        ]);
    }

    /**
     * List pending invitations for this institution (manager only)
     */
    public function listInvites(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        // Join with users table to include inviter name when available
        $invites = DB::table('institution_user as iu')
            ->leftJoin('users as u', 'iu.invited_by', '=', 'u.id')
            ->where('iu.institution_id', $institution->id)
            ->where('iu.invitation_status', 'invited')
            ->where('iu.invitation_expires_at', '>', now())
            ->orderByDesc('iu.created_at')
            ->select(['iu.id', 'iu.invited_email', 'iu.role', 'iu.invitation_token', 'iu.invitation_expires_at', 'iu.invited_by', 'iu.created_at', 'u.name as invited_by_name'])
            ->get()
            ->map(function ($i) {
                return [
                    'id' => $i->id,
                    'email' => $i->invited_email,
                    'role' => $i->role,
                    'invitation_token' => $i->invitation_token,
                    'expires_at' => $i->invitation_expires_at,
                    'invited_by' => $i->invited_by,
                    'invited_by_name' => $i->invited_by_name ?? null,
                    'created_at' => $i->created_at,
                ];
            });

        return response()->json(['ok' => true, 'invites' => $invites]);
    }

    /**
     * Audit: list accepted invitations history for this institution (manager only)
     */
    public function listAcceptedInvites(Request $request, Institution $institution)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        // Find pivot rows that were created as invites and later accepted (user_id set and invitation_status active)
        $rows = DB::table('institution_user as iu')
            ->leftJoin('users as inviter', 'iu.invited_by', '=', 'inviter.id')
            ->leftJoin('users as accepted', 'iu.user_id', '=', 'accepted.id')
            ->where('iu.institution_id', $institution->id)
            ->where('iu.invitation_status', 'active')
            ->whereNotNull('iu.invited_email')
            ->orderByDesc('iu.updated_at')
            ->select([
                'iu.id', 'iu.invited_email', 'iu.role', 'iu.invited_by', 'inviter.name as invited_by_name',
                'iu.user_id as accepted_user_id', 'accepted.name as accepted_user_name',
                'iu.created_at as invited_at', 'iu.updated_at as accepted_at'
            ])
            ->get();

        $result = $rows->map(function ($r) {
            return [
                'id' => $r->id,
                'invited_email' => $r->invited_email,
                'role' => $r->role,
                'invited_by' => $r->invited_by,
                'invited_by_name' => $r->invited_by_name ?? null,
                'accepted_user_id' => $r->accepted_user_id,
                'accepted_user_name' => $r->accepted_user_name ?? null,
                'invited_at' => $r->invited_at,
                'accepted_at' => $r->accepted_at,
            ];
        });

        return response()->json(['ok' => true, 'accepted' => $result]);
    }

    /**
     * Revoke a pending invitation by token (manager only)
     */
    public function revokeInvite(Request $request, Institution $institution, $token)
    {
        $user = $request->user();
        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);

        $inv = DB::table('institution_user')
            ->where('institution_id', $institution->id)
            ->where('invitation_token', $token)
            ->where('invitation_status', 'invited')
            ->first();

        if (!$inv) {
            return response()->json(['ok' => false, 'message' => 'Invitation not found or already handled'], 404);
        }

        DB::table('institution_user')->where('id', $inv->id)->update([
            'invitation_status' => 'revoked',
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'updated_at' => now(),
        ]);

        \Log::info('Invitation revoked', ['institution_id' => $institution->id, 'invited_email' => $inv->invited_email, 'revoked_by' => $user->id]);

        return response()->json(['ok' => true, 'message' => 'Invitation revoked']);
    }

    /**
     * Get analytics overview
     */
    public function analyticsOverview(Request $request, Institution $institution)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $now = now();
        $weekAgo = now()->subDays(7);

        $totalMembers = $institution->users()->count();
        $quizees = $institution->users()->wherePivot('role', 'quizee')->count();
        $quizMasters = $institution->users()->wherePivot('role', 'quiz-master')->count();

        $memberIds = $institution->users()->pluck('users.id')->toArray();

        $activeToday = 0;
        $activeThisWeek = 0;
        if (!empty($memberIds)) {
            $activeToday = DB::table('quiz_attempts')
                ->whereIn('user_id', $memberIds)
                ->whereDate('created_at', $now)
                ->count();

            $activeThisWeek = DB::table('quiz_attempts')
                ->whereIn('user_id', $memberIds)
                ->whereBetween('created_at', [$weekAgo, $now])
                ->count() > 0 ? DB::table('users')
                ->whereIn('id', $memberIds)
                ->whereHas('quizAttempts', function ($q) use ($weekAgo, $now) {
                    $q->whereBetween('created_at', [$weekAgo, $now]);
                })->count() : 0;
        }

        $totalAttempts = 0;
        $avgScore = 0;
        if (!empty($memberIds)) {
            $attempts = DB::table('quiz_attempts')->whereIn('user_id', $memberIds)->get();
            $totalAttempts = $attempts->count();
            if ($totalAttempts > 0) {
                $avgScore = round($attempts->avg('score'), 2);
            }
        }

        $activeSub = $institution->activeSubscription();
        $seatsTotal = $activeSub && $activeSub->package ? $activeSub->package->seats : 0;
        $seatsAssigned = $activeSub ? $activeSub->assignments()->whereNull('revoked_at')->count() : 0;
        $seatsAvailable = $seatsTotal > 0 ? $seatsTotal - $seatsAssigned : 0;
        $utilizationRate = $seatsTotal > 0 ? round(($seatsAssigned / $seatsTotal) * 100, 2) : 0;

        return response()->json([
            'ok' => true,
            'analytics' => [
                'members' => [
                    'total' => $totalMembers,
                    'quizees' => $quizees,
                    'quiz_masters' => $quizMasters,
                    'active_today' => $activeToday,
                    'active_this_week' => $activeThisWeek
                ],
                'quizzes' => [
                    'total_attempts' => $totalAttempts,
                    'avg_score' => $avgScore
                ],
                'subscription' => [
                    'seats_total' => $seatsTotal,
                    'seats_assigned' => $seatsAssigned,
                    'seats_available' => $seatsAvailable,
                    'utilization_rate' => $utilizationRate
                ]
            ]
        ]);
    }

    /**
     * Get activity trends
     */
    public function analyticsActivity(Request $request, Institution $institution)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $days = (int) $request->input('days', 30);
        $startDate = now()->subDays($days);

        $memberIds = $institution->users()->pluck('users.id')->toArray();

        $activity = DB::table('quiz_attempts')
            ->whereIn('user_id', $memberIds)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as attempts, AVG(score) as avg_score')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'ok' => true,
            'analytics' => [
                'period_days' => $days,
                'activity_by_date' => $activity
            ]
        ]);
    }

    /**
     * Get performance distribution
     */
    public function analyticsPerformance(Request $request, Institution $institution)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $memberIds = $institution->users()->pluck('users.id')->toArray();

        $ranges = [
            ['min' => 0, 'max' => 20, 'label' => '0-20%'],
            ['min' => 20, 'max' => 40, 'label' => '20-40%'],
            ['min' => 40, 'max' => 60, 'label' => '40-60%'],
            ['min' => 60, 'max' => 80, 'label' => '60-80%'],
            ['min' => 80, 'max' => 100, 'label' => '80-100%'],
        ];

        $distribution = [];
        foreach ($ranges as $range) {
            $count = DB::table('quiz_attempts')
                ->whereIn('user_id', $memberIds)
                ->whereBetween('score', [$range['min'], $range['max']])
                ->count();
            $distribution[] = [
                'range' => $range['label'],
                'count' => $count
            ];
        }

        // Efficiently fetch top performers with a join to users to avoid N+1 queries
        $topPerformersFormatted = DB::table('quiz_attempts as qa')
            ->join('users as u', 'u.id', '=', 'qa.user_id')
            ->whereIn('qa.user_id', $memberIds)
            ->selectRaw('qa.user_id as user_id, u.name as user_name, AVG(qa.score) as avg_score, COUNT(*) as attempts')
            ->groupBy('qa.user_id', 'u.name')
            ->orderByDesc('avg_score')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'user_id' => $item->user_id,
                    'name' => $item->user_name ?? 'Unknown',
                    'avg_score' => round($item->avg_score, 2),
                    'attempts' => $item->attempts
                ];
            });

        return response()->json([
            'ok' => true,
            'analytics' => [
                'score_distribution' => $distribution,
                'top_performers' => $topPerformersFormatted
            ]
        ]);
    }

    /**
     * Get member engagement details
     */
    public function analyticsMember(Request $request, Institution $institution, $userId)
    {
        $user = $request->user();

        $isManager = $institution->users()->where('users.id', $user->id)->wherePivot('role', 'institution-manager')->exists();
        if (!$isManager) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $member = $institution->users()->where('users.id', $userId)->first();
        if (!$member) {
            return response()->json(['ok' => false, 'message' => 'Member not found'], 404);
        }

        $pivot = $member->pivot;
        $attempts = DB::table('quiz_attempts')->where('user_id', $userId)->get();
        $totalAttempts = $attempts->count();
        $avgScore = $totalAttempts > 0 ? round($attempts->avg('score'), 2) : 0;
        $lastActivity = DB::table('quiz_attempts')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();

        $memberSince = $pivot->created_at ? \Carbon\Carbon::parse($pivot->created_at) : null;
        $daysSince = $memberSince ? $memberSince->diffInDays(now()) : 0;

        return response()->json([
            'ok' => true,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $pivot->role,
                'status' => $pivot->status,
                'member_since' => $memberSince ? $memberSince->toDateString() : null,
                'days_as_member' => $daysSince,
                'activity' => [
                    'total_attempts' => $totalAttempts,
                    'avg_score' => $avgScore,
                    'last_activity' => $lastActivity ? $lastActivity->created_at : null
                ]
            ]
        ]);
    }
}
