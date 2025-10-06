<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\SocialAuthService;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    protected $socialAuthService;

    public function __construct(SocialAuthService $socialAuthService)
    {
        $this->socialAuthService = $socialAuthService;
    }

    /**
     * Redirect the user to the provider authentication page.
     */
    public function redirect(Request $request, string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the provider callback.
     */
    public function callback(Request $request, string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
            $user = $this->socialAuthService->findOrCreateUser($socialUser, $provider);

            // Create token for API authentication
            $token = $user->createToken('auth_token')->plainTextToken;

            // If profile is not complete, return different response
            if (!$user->is_profile_completed) {
                return response()->json([
                    'token' => $token,
                    'user' => $user,
                    'requires_profile_completion' => true,
                    'next_step' => $this->determineNextStep($user),
                ], 200);
            }

            return response()->json([
                'token' => $token,
                'user' => $user,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred during social authentication.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine the next step in the onboarding process.
     */
    protected function determineNextStep($user)
    {
        $onboarding = $user->onboarding;

        if (!$onboarding->institution_added) {
            return 'institution';
        }

        if (!$onboarding->role_selected) {
            return 'role';
        }

        // Based on role, determine next step
        $completedSteps = $onboarding->completed_steps ?? [];

        if (in_array('role_student', $completedSteps) && !$onboarding->grade_selected) {
            return 'grade';
        }

        if (in_array('role_tutor', $completedSteps) && !$onboarding->subject_selected) {
            return 'subjects';
        }

        return 'complete';
    }
}
