<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\SocialAuthService;
use App\Models\UserOnboarding;
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
        // If a 'next' URL is provided, store it in the session to redirect after login.
        if ($request->has('next')) {
            // Use a specific session key to avoid conflicts.
            session(['auth_redirect_url' => $request->input('next')]);
        }

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

            // Log the user into the session with persistent login
            Auth::login($user, true);

            // Regenerate session for security (prevents session fixation attacks)
            $request->session()->regenerate();

            // Load user with onboarding relationship
            $user->load('onboarding');

            // Ensure onboarding record exists for users who need it
            $hasRole = !empty($user->role);
            $needsOnboarding = !$hasRole || !$user->is_profile_completed;
            
            if ($needsOnboarding && !$user->onboarding) {
                UserOnboarding::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'profile_completed' => false,
                        'institution_added' => false,
                        'role_selected' => !empty($user->role),
                        'subject_selected' => false,
                        'grade_selected' => false,
                        'completed_steps' => ['social_auth'],
                        'last_step_completed_at' => now()
                    ]
                );
                $user->load('onboarding');
            }

            $requiresCompletion = $needsOnboarding;
            $nextStep = $this->determineNextStep($user);

            // If the request expects JSON (API/client), return JSON with user data
            if ($request->wantsJson() || $request->ajax() || str_contains($request->header('accept',''), '/json')) {
                return response()->json([
                    'user' => $user,
                    'requires_profile_completion' => $requiresCompletion,
                    'next_step' => $nextStep,
                ], 200);
            }

            // Browser OAuth flow: redirect back to frontend
            $frontend = env('FRONTEND_APP_URL', config('app.url') ?? 'http://localhost:3000');
            $nextUrl = session()->pull('auth_redirect_url', '/auth/callback');

            if (!str_starts_with($nextUrl, '/')) {
                $nextUrl = '/auth/callback';
            }

            $query = http_build_query([
                'requires_profile_completion' => $requiresCompletion ? '1' : '0',
                'next_step' => $nextStep,
            ]);

            $redirectUrl = rtrim($frontend, '/') . $nextUrl . '?' . $query;
            return redirect()->to($redirectUrl);

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
        // If user has no role, they need to choose role first (new users)
        if (empty($user->role)) {
            return 'new-user';
        }

        $onboarding = $user->onboarding;

        // If there's no onboarding record yet, create one or determine based on role
        // For existing users without onboarding record, check if they have a role
        // If they have a role and profile is complete, they're done
        if (!$onboarding) {
            // Existing user without onboarding record but has role and completed profile
            if (!empty($user->role) && $user->is_profile_completed) {
                return 'complete';
            }
            // Otherwise, they need to start onboarding - choose role first
            return 'new-user';
        }

        // Priority: role selection comes first for new users
        // If role is not selected, go to role selection
        if (empty($onboarding->role_selected) || !$onboarding->role_selected) {
            return 'new-user';
        }

        // After role is selected, check institution
        if (empty($onboarding->institution_added) || !$onboarding->institution_added) {
            return 'institution';
        }

        // Based on role, determine next step. completed_steps may be stored as array or JSON string.
        $completedSteps = $onboarding->completed_steps ?? [];
        if (is_string($completedSteps)) {
            $decoded = json_decode($completedSteps, true);
            $completedSteps = is_array($decoded) ? $decoded : [];
        }

        // Check role-specific requirements
        if ($user->role === 'quizee' && (empty($onboarding->grade_selected) || !$onboarding->grade_selected)) {
            return 'grade';
        }

        if ($user->role === 'quiz-master' && (empty($onboarding->subject_selected) || !$onboarding->subject_selected)) {
            return 'subjects';
        }

        return 'complete';
    }
}
