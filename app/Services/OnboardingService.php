<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    /**
     * Mark a step completed for a user and finalize profile if all steps done.
     * Returns the updated onboarding model.
     */
    public function completeStep(User $user, string $step, array $data = [])
    {
        return DB::transaction(function () use ($user, $step, $data) {
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

            // Map common step names to flags
            switch ($step) {
                case 'institution':
                    $onboarding->institution_added = true;
                    break;
                case 'role_student':
                case 'role_tutor':
                    $onboarding->role_selected = true;
                    break;
                case 'grade':
                    $onboarding->grade_selected = true;
                    break;
                case 'subjects':
                    $onboarding->subject_selected = true;
                    break;
                case 'profile_complete':
                    // explicit completion request
                    $onboarding->profile_completed = true;
                    break;
            }

            $onboarding->last_step_completed_at = now();
            $onboarding->save();

            // Determine if profile is complete based on flags or explicit step
            $isComplete = $onboarding->profile_completed
                || ($onboarding->institution_added && $onboarding->role_selected
                    && ($onboarding->grade_selected || $onboarding->subject_selected || true));

            if ($onboarding->profile_completed) {
                $isComplete = true;
            }

            // If complete, set user flag as well
            if ($isComplete && !$user->is_profile_completed) {
                $user->is_profile_completed = true;
                $user->save();
            }

            return $onboarding->fresh();
        });
    }
}
