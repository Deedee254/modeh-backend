<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use App\Models\Grade;
use App\Models\Institution;
use App\Models\InstitutionApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OnboardingService
{
    /**
     * Mark a step completed for a user and finalize profile if all steps done.
     * Returns the updated onboarding model.
     */
    public function completeStep(User $user, string $step, array $data = [])
    {
        return DB::transaction(function () use ($user, $step, $data) {
            $skipped = !empty($data['skipped']) && $data['skipped'] === true;

            $onboarding = UserOnboarding::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'profile_completed' => false,
                    'institution_added' => false,
                    'role_selected' => false,
                    'subject_selected' => false,
                    'grade_selected' => false,
                    'completed_steps' => [],
                ]
            );

            $completed = $onboarding->completed_steps ?? [];
            if (!in_array($step, $completed)) {
                $completed[] = $step;
                $onboarding->completed_steps = $completed;
            }

            // Map common step names to flags. If the step was skipped, avoid side effects
            switch ($step) {
                case 'institution':
                    if (! $skipped) {
                        $onboarding->institution_added = true;
                        $this->handleInstitutionStep($user, $data);
                    }
                    break;
                case 'role_quizee':
                case 'role_quiz-master':
                    if (! $skipped) {
                        // Role selection step: update user's role and optional password
                        $onboarding->role_selected = true;
                        if (!empty($data['role'])) {
                            $user->role = $data['role'];
                        } else {
                            // Fallback based on step name
                            $user->role = $step === 'role_quiz-master' ? 'quiz-master' : 'quizee';
                        }

                        // If a password is provided, set it (User model casts 'password' => 'hashed')
                        if (!empty($data['password'])) {
                            $user->forceFill(['password' => bcrypt($data['password'])])->save();
                        }

                        $user->save();
                    }
                    break;
                case 'grade':
                    if (! $skipped) {
                        $onboarding->grade_selected = true;
                        if (!empty($data['grade_id'])) {
                            $grade = Grade::find($data['grade_id']);
                            $update = ['grade_id' => $data['grade_id']];
                            if ($grade && isset($grade->level_id)) {
                                $update['level_id'] = $grade->level_id;
                            }

                            // If role is known, update the corresponding profile; otherwise update both profiles
                            if ($user->role === 'quiz-master') {
                                $user->quizMasterProfile->update($update);
                            } elseif ($user->role === 'quizee') {
                                $user->quizeeProfile->update($update);
                            } else {
                                // create profiles if missing and update both so the data is retained regardless of later role selection
                                $quizee = $user->quizeeProfile ?? $user->quizeeProfile()->create([]);
                                $quizMaster = $user->quizMasterProfile ?? $user->quizMasterProfile()->create([]);
                                $quizee->update($update);
                                $quizMaster->update($update);
                            }
                        }
                    }
                    break;
                case 'subjects':
                    if (! $skipped) {
                        $onboarding->subject_selected = true;
                        if (!empty($data['subjects'])) {
                            // If role known, update corresponding profile; otherwise preserve subjects on both profiles
                            if ($user->role === 'quiz-master') {
                                $user->quizMasterProfile->update(['subjects' => $data['subjects']]);
                            } elseif ($user->role === 'quizee') {
                                $user->quizeeProfile->update(['subjects' => $data['subjects']]);
                            } else {
                                $quizee = $user->quizeeProfile ?? $user->quizeeProfile()->create([]);
                                $quizMaster = $user->quizMasterProfile ?? $user->quizMasterProfile()->create([]);
                                $quizee->update(['subjects' => $data['subjects']]);
                                $quizMaster->update(['subjects' => $data['subjects']]);
                            }
                        }
                    }
                    break;
                case 'profile_complete':
                    // explicit completion request
                    $onboarding->profile_completed = true;
                    break;
            }

            $onboarding->last_step_completed_at = now();
            $onboarding->save();

            // Determine if profile is complete based on role-specific requirements
            $isComplete = false;

            if ($step === 'profile_complete') {
                // Explicit completion request
                $isComplete = true;
            } elseif ($user->role === 'quizee') {
                // Quizees must have: institution, role, and grade
                $isComplete = $onboarding->institution_added &&
                              $onboarding->role_selected &&
                              $onboarding->grade_selected;
            } elseif ($user->role === 'quiz-master') {
                // Quiz Masters must have: institution, role, and subjects
                $isComplete = $onboarding->institution_added &&
                              $onboarding->role_selected &&
                              $onboarding->subject_selected;
            } else {
                // Default: institution + role
                $isComplete = $onboarding->institution_added &&
                              $onboarding->role_selected;
            }

            // If complete, set user flag as well
            if ($isComplete && !$user->is_profile_completed) {
                $user->is_profile_completed = true;
                $user->save();
            }

            return $onboarding->fresh();
        });
    }

    /**
     * Create institution approval request if institution doesn't already exist
     */
    private function createApprovalRequestIfNeeded(User $user, $profile, string $profileType, string $institutionText)
    {
        // Check if institution already exists by name or slug
        $existingInstitution = Institution::where('name', $institutionText)
            ->orWhere('slug', \Str::slug($institutionText))
            ->first();

        if ($existingInstitution) {
            // Institution exists - automatically link it
            $profile->update(['institution_id' => $existingInstitution->id]);
            return;
        }

        // Check if approval request already pending for this user and institution
        $existingRequest = InstitutionApprovalRequest::where('user_id', $user->id)
            ->where('institution_name', $institutionText)
            ->where('profile_type', $profileType)
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            return;  // Already submitted
        }

        // Create new approval request
        InstitutionApprovalRequest::create([
            'institution_name' => $institutionText,
            'user_id' => $user->id,
            'profile_type' => $profileType,
            'profile_id' => $profile->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Handle institution step for onboarding - works for both quizee and quiz-master
     * Supports branch_id to link user to a specific sub-institution/branch
     */
    private function handleInstitutionStep(User $user, array $data)
    {
        $institutionId = $data['institution_id'] ?? null;
        $institutionText = $data['institution'] ?? null;
        $branchId = $data['branch_id'] ?? null;

        // Get or create profile based on role
        if ($user->role === 'quizee') {
            $profile = $user->quizeeProfile ?? $user->quizeeProfile()->create([]);
            $profileType = 'quizee';
        } else {
            $profile = $user->quizMasterProfile ?? $user->quizMasterProfile()->create([]);
            $profileType = 'quiz-master';
        }

        // Link to existing institution or store as text
        if ($institutionId) {
            $updateData = ['institution_id' => $institutionId];
            
            // If branch_id is provided, also store it (for multi-branch institutions)
            if ($branchId) {
                $updateData['branch_id'] = $branchId;
            }
            
            $profile->update($updateData);
        } elseif ($institutionText) {
            $profile->update(['institution' => $institutionText]);
            $this->createApprovalRequestIfNeeded($user, $profile, $profileType, $institutionText);
        }
    }
}
