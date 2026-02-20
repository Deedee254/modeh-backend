<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InstitutionApprovalRequest;
use App\Services\InstitutionPackageUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class InstitutionApprovalController extends Controller
{
    /**
     * Get pending approval requests for an institution
     */
    public function pending(Request $request, Institution $institution)
    {
        $user = $request->user();

        if (!$this->isInstitutionManager($institution, $user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $requests = InstitutionApprovalRequest::where('institution_name', $institution->name)
            ->orWhere('institution_name', $institution->slug)
            ->where('status', 'pending')
            ->with('user', 'quizee', 'quizMaster')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['requests' => $requests]);
    }

    /**
     * Approve an institution request
     */
    public function approve(Request $request, InstitutionApprovalRequest $approvalRequest)
    {
        $user = $request->user();

        if (!$this->isAnyInstitutionManager($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($approvalRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already processed'], 422);
        }

        $institution = $this->findOrCreateInstitution($approvalRequest->institution_name);
        $approvalRequest->approve($institution->id, $user->id);

        // Add user to institution if not already member
        if (!$institution->users()->where('users.id', $approvalRequest->user_id)->exists()) {
            $institution->users()->attach($approvalRequest->user_id, [
                'role' => $approvalRequest->profile_type,
                'status' => 'active',
            ]);
            
            // Record seat usage when approved user is added
            try {
                $approvedUser = \App\Models\User::find($approvalRequest->user_id);
                if ($approvedUser) {
                    InstitutionPackageUsageService::recordSeatUsage($institution, $approvedUser);
                    Log::info('[Institution] Seat usage recorded for approved member', [
                        'institution_id' => $institution->id,
                        'user_id' => $approvalRequest->user_id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('[Institution] Failed to record seat usage for approved member: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Approved',
            'institution' => $institution,
            'request' => $approvalRequest->fresh(),
        ]);
    }

    /**
     * Reject an institution request
     */
    public function reject(Request $request, InstitutionApprovalRequest $approvalRequest)
    {
        $user = $request->user();

        if (!$this->isAnyInstitutionManager($user)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($approvalRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already processed'], 422);
        }

        $approvalRequest->reject($user->id, $request->input('notes'));

        return response()->json(['message' => 'Rejected', 'request' => $approvalRequest->fresh()]);
    }

    /**
     * Check if user is manager of specific institution
     */
    private function isInstitutionManager(Institution $institution, $user): bool
    {
        return $institution->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'institution-manager')
            ->exists();
    }

    /**
     * Check if user is manager of any institution
     */
    private function isAnyInstitutionManager($user): bool
    {
        return Institution::whereHas('users', function ($q) use ($user) {
            $q->where('users.id', $user->id)
                ->wherePivot('role', 'institution-manager');
        })->exists();
    }

    /**
     * Find or create institution
     */
    private function findOrCreateInstitution(string $name): Institution
    {
        $institution = Institution::where('name', $name)
            ->orWhere('slug', Str::slug($name))
            ->first();

        if (!$institution) {
            $institution = Institution::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'is_active' => true,
            ]);
        }

        return $institution;
    }
}
