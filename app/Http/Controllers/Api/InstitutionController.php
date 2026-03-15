<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Institution;
use App\Models\InstitutionApprovalRequest;
use App\Models\InstitutionMember;
use Illuminate\Support\Facades\Auth;

class InstitutionController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);

        $query = Institution::query();
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->query('name') . '%');
        }

        $institutions = $query->with('children', 'users')->paginate($perPage);

        return response()->json($institutions);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent' => 'nullable|string', // slug or id of parent institution (branch creation)
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:50',
            'logo_url' => 'nullable|string|max:1000',
            'website' => 'nullable|url',
            'address' => 'nullable|string|max:500',
            'metadata' => 'nullable|array',
        ]);

        // Resolve parent if provided (accept id or slug)
        $parentId = null;
        if (!empty($data['parent'])) {
            $p = $data['parent'];
            $q = Institution::query();
            if (ctype_digit(strval($p))) {
                $q->orWhere('id', (int)$p);
            }
            $q->orWhere('slug', $p);
            $parent = $q->first();
            if ($parent) $parentId = $parent->id;
        }

        $institution = Institution::create(array_merge($data, ['created_by' => $user->id, 'parent_id' => $parentId]));

        // Attach the creator as institution-manager
        $institution->users()->attach($user->id, [
            'role' => 'institution-manager',
            'status' => 'active',
            'invited_by' => null,
        ]);

        return response()->json($institution, 201);
    }

    public function show(Institution $institution)
    {
        $institution->load('users', 'children');
        return response()->json($institution);
    }

    public function update(Request $request, Institution $institution)
    {
        // Only institution managers can update
        $user = $request->user();
        $isManager = $institution->users()->where('user_id', $user->id)->where('role', 'institution-manager')->exists();
        if (!$isManager && !($user && $user->isAdmin())) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email',
            'phone' => 'sometimes|nullable|string|max:50',
            'logo_url' => 'sometimes|nullable|string|max:1000',
            'website' => 'sometimes|nullable|url',
            'address' => 'sometimes|nullable|string|max:500',
            'metadata' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
        ]);

        $institution->update($data);

        return response()->json($institution);
    }

    /**
     * ADMIN METHODS - Get institution members
     */
    public function members($id)
    {
        try {
            $institution = Institution::find($id);

            if (!$institution) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Institution not found',
                ], 404);
            }

            $members = InstitutionMember::where('institution_id', $id)
                ->with(['user' => function ($query) {
                    $query->select('id', 'name', 'email', 'profile_type');
                }])
                ->get()
                ->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'user_id' => $member->user_id,
                        'institution_id' => $member->institution_id,
                        'role' => $member->role ?? 'member',
                        'joined_at' => $member->joined_at ?? $member->created_at,
                        'user' => [
                            'id' => $member->user->id,
                            'name' => $member->user->name,
                            'email' => $member->user->email,
                            'profile_type' => $member->user->profile_type ?? 'quizee',
                        ],
                    ];
                });

            return response()->json([
                'ok' => true,
                'data' => $members,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching members',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get pending institution join requests
     */
    public function requests(Request $request)
    {
        try {
            $query = InstitutionApprovalRequest::with(['user']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            } else {
                // Default to pending if not specified
                $query->where('status', 'pending');
            }

            $requests = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($req) {
                    return [
                        'id' => $req->id,
                        'user_id' => $req->user_id,
                        'institution_id' => $req->institution_id,
                        'profile_type' => $req->profile_type ?? 'quizee',
                        'institution_name' => $req->institution_name,
                        'institution_location' => $req->institution_location ?? null,
                        'status' => $req->status,
                        'rejection_reason' => $req->rejection_reason ?? null,
                        'created_at' => $req->created_at,
                        'user' => [
                            'id' => $req->user->id,
                            'name' => $req->user->name,
                            'email' => $req->user->email,
                        ],
                    ];
                });

            return response()->json([
                'ok' => true,
                'data' => $requests,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching requests',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Approve institution join request
     */
    public function approveRequest($id)
    {
        try {
            $approvalRequest = InstitutionApprovalRequest::find($id);

            if (!$approvalRequest) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Request not found',
                ], 404);
            }

            // Check if already processed
            if ($approvalRequest->status !== 'pending') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Request has already been ' . $approvalRequest->status,
                ], 422);
            }

            // If no institution exists yet, create one
            $institution = null;
            if ($approvalRequest->institution_id) {
                $institution = Institution::find($approvalRequest->institution_id);
            } else {
                // Create new institution if this is a new institution request
                $institution = Institution::create([
                    'name' => $approvalRequest->institution_name,
                    'email' => $approvalRequest->user->email ?? '',
                    'phone' => $approvalRequest->user->phone ?? '',
                    'address' => $approvalRequest->institution_location ?? '',
                    'is_active' => true,
                ]);
            }

            // Add user as member
            InstitutionMember::firstOrCreate(
                [
                    'user_id' => $approvalRequest->user_id,
                    'institution_id' => $institution->id,
                ],
                [
                    'role' => $approvalRequest->profile_type === 'quiz-master' ? 'instructor' : 'student',
                    'joined_at' => now(),
                ]
            );

            // Update request status
            $approvalRequest->update([
                'status' => 'approved',
                'institution_id' => $institution->id,
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Request approved successfully',
                'data' => [
                    'request_id' => $approvalRequest->id,
                    'institution_id' => $institution->id,
                    'status' => 'approved',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error approving request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Reject institution join request
     */
    public function rejectRequest(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|max:500',
            ]);

            $approvalRequest = InstitutionApprovalRequest::find($id);

            if (!$approvalRequest) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Request not found',
                ], 404);
            }

            // Check if already processed
            if ($approvalRequest->status !== 'pending') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Request has already been ' . $approvalRequest->status,
                ], 422);
            }

            // Update request status
            $approvalRequest->update([
                'status' => 'rejected',
                'rejection_reason' => $validated['reason'],
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Request rejected successfully',
                'data' => [
                    'request_id' => $approvalRequest->id,
                    'status' => 'rejected',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error rejecting request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ADMIN METHODS - Get institution metrics
     */
    public function adminMetrics()
    {
        try {
            $totalInstitutions = Institution::count();
            $totalMembers = InstitutionMember::count();
            $pendingRequests = InstitutionApprovalRequest::where('status', 'pending')->count();
            $approvedRequests = InstitutionApprovalRequest::where('status', 'approved')->count();

            return response()->json([
                'ok' => true,
                'data' => [
                    'total_institutions' => $totalInstitutions,
                    'total_members' => $totalMembers,
                    'pending_requests' => $pendingRequests,
                    'approved_requests' => $approvedRequests,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error fetching metrics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
