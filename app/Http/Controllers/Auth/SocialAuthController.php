<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\SocialAuthService;
use Illuminate\Support\Facades\Auth;
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

            // Log the user into the session so Sanctum/cookie-based endpoints work
            try { Auth::login($user); } catch (\Throwable $ex) { /* ignore session issues */ }

            // Create token for API authentication (also returned to SPA as a convenience)
            $token = $user->createToken('auth_token')->plainTextToken;

            // If profile is not complete, return different response
            $requiresCompletion = !$user->is_profile_completed;
            $nextStep = $this->determineNextStep($user);

            // If the request expects JSON (API/client), return JSON as before.
            if ($request->wantsJson() || $request->ajax() || str_contains($request->header('accept',''), '/json')) {
                return response()->json([
                    'token' => $token,
                    'user' => $user,
                    'requires_profile_completion' => $requiresCompletion,
                    'next_step' => $nextStep,
                ], 200);
            }

            // Otherwise assume this is a browser OAuth flow - redirect back to frontend
            // Frontend will handle storing the token and routing the user. Configure FRONTEND_APP_URL in .env
            $frontend = env('FRONTEND_APP_URL', config('app.url') ?? 'http://localhost:3000');

            // Build redirect URL (we place data in query string). Token is sent in query for simplicity.
            $query = http_build_query([
                'token' => $token,
                'requires_profile_completion' => $requiresCompletion ? '1' : '0',
                'next_step' => $nextStep,
            ]);

            return redirect()->to(rtrim($frontend, '/') . '/auth/callback?' . $query);

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
        $onboarding = $user->onboarding ?? null;

        // If there's no onboarding record yet, treat as not started and ask for institution
        if (!$onboarding || empty($onboarding)) {
            return 'institution';
        }

        // Null-safe checks for boolean flags
        if (empty($onboarding->institution_added) || !$onboarding->institution_added) {
            return 'institution';
        }

        if (empty($onboarding->role_selected) || !$onboarding->role_selected) {
            return 'role';
        }

        // Based on role, determine next step. completed_steps may be stored as array or JSON string.
        $completedSteps = $onboarding->completed_steps ?? [];
        if (is_string($completedSteps)) {
            $decoded = json_decode($completedSteps, true);
            $completedSteps = is_array($decoded) ? $decoded : [];
        }

        if (in_array('role_quizee', (array)$completedSteps) && empty($onboarding->grade_selected)) {
            return 'grade';
        }

        if (in_array('role_quiz-master', (array)$completedSteps) && empty($onboarding->subject_selected)) {
            return 'subjects';
        }

        return 'complete';
    }
}
