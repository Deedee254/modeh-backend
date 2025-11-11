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

            // Log the user into the session. Keep the session active during onboarding
            // so that password and role can be set. The session cookie will persist
            // across onboarding requests, and after profile completion the user remains authenticated.
            Auth::login($user);

            // Revoke old tokens before issuing a new one. This ensures only the latest
            // token is valid, preventing token accumulation and unauthorized reuse.
            $this->socialAuthService->revokeAllTokens($user);

            // Create a token with expiry for API-based authentication (JSON responses).
            // Token expires in 30 days by default (configurable).
            $tokenExpiresIn = config('sanctum.expiration', 30) * 24 * 60; // in minutes
            $token = $user->createToken('auth_token', ['*'], now()->addMinutes($tokenExpiresIn))->plainTextToken;

            // If profile is not complete, return different response
            $requiresCompletion = !$user->is_profile_completed;
            $nextStep = $this->determineNextStep($user);

            // If the request expects JSON (API/client), return JSON with token.
            if ($request->wantsJson() || $request->ajax() || str_contains($request->header('accept',''), '/json')) {
                return response()->json([
                    'token' => $token,
                    'user' => $user,
                    'requires_profile_completion' => $requiresCompletion,
                    'next_step' => $nextStep,
                ], 200);
            }

            // Browser OAuth flow: redirect back to frontend.
            // Session cookie is automatically included by browser (Sanctum handles this).
            // Additionally, set a secure HttpOnly cookie with the token for fallback API auth.
            $frontend = env('FRONTEND_APP_URL', config('app.url') ?? 'http://localhost:3000');
            $nextUrl = session()->pull('auth_redirect_url', '/auth/callback');

            // Ensure the path starts with a slash to prevent open redirect vulnerabilities.
            if (!str_starts_with($nextUrl, '/')) {
                $nextUrl = '/auth/callback';
            }

            // Build redirect URL with only safe metadata (no token in URL).
            // The session cookie and secure token cookie will authenticate subsequent requests.
            $query = http_build_query([
                'requires_profile_completion' => $requiresCompletion ? '1' : '0',
                'next_step' => $nextStep,
            ]);

            // Set secure HttpOnly token cookie (if frontend and backend on same top-level domain or cookies configured).
            // Expires in same duration as token. SameSite=Lax for cross-site request tolerance (OAuth redirect).
            $redirectUrl = rtrim($frontend, '/') . $nextUrl . '?' . $query;
            $cookie = \Illuminate\Support\Facades\Cookie::make(
                'auth_token',
                $token,
                $tokenExpiresIn,
                '/',
                config('session.domain'), // e.g., '.example.com' for cross-subdomain
                config('session.secure', true), // HTTPS only
                true, // HttpOnly
                false, // raw
                config('session.same_site', 'Lax') // SameSite attribute
            );

            return redirect()->to($redirectUrl)->withCookie($cookie);

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
