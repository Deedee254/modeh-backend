<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use App\Models\Grade;
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
                    if ($user->role === 'quiz-master' && !empty($data['institution'])) {
                        // Create or update quiz master profile
                        $profile = $user->quizMasterProfile ?? $user->quizMasterProfile()->create([]);
                        $profile->update(['institution' => $data['institution']]);
                    } elseif ($user->role === 'quizee' && !empty($data['institution'])) {
                        // Create or update quizee profile
                        $profile = $user->quizeeProfile ?? $user->quizeeProfile()->create([]);
                        $profile->update(['institution' => $data['institution']]);
                    }
                    break;
                case 'role_quizee':
                case 'role_quiz-master':
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
                    break;
                case 'grade':
                    $onboarding->grade_selected = true;
                    if ($user->role === 'quiz-master' && !empty($data['grade_id'])) {
                        $user->quizMasterProfile->update(['grade_id' => $data['grade_id']]);
                    } elseif ($user->role === 'quizee' && !empty($data['grade_id'])) {
                        // Update quizee profile grade and also persist the associated level
                        $grade = Grade::find($data['grade_id']);
                        $update = ['grade_id' => $data['grade_id']];
                        if ($grade && isset($grade->level_id)) {
                            $update['level_id'] = $grade->level_id;
                        }
                        $user->quizeeProfile->update($update);
                    }
                    break;
                case 'subjects':
                    $onboarding->subject_selected = true;
                    if ($user->role === 'quiz-master' && !empty($data['subjects'])) {
                        $user->quizMasterProfile->update(['subjects' => $data['subjects']]);
                    } elseif ($user->role === 'quizee' && !empty($data['subjects'])) {
                        $user->quizeeProfile->update(['subjects' => $data['subjects']]);
                    }
                    break;
                case 'profile_complete':
                    // explicit completion request
                    $onboarding->profile_completed = true;
                    break;
            }

            $onboarding->last_step_completed_at = now();
            $onboarding->save();

            // Determine if profile is complete based on flags or explicit step
            // Consider profile complete if institution and role are set
            // Grade and subjects are optional and can be completed later via complete-profile
            $isComplete = $step === 'profile_complete' || 
                         ($onboarding->institution_added && $onboarding->role_selected);

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
