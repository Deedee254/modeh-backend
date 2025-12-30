<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SessionService;
use App\Services\SocialAuthService;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SocialLoginController extends Controller
{
    protected $socialAuthService;
    protected $sessionService;

    public function __construct(SocialAuthService $socialAuthService, SessionService $sessionService)
    {
        $this->socialAuthService = $socialAuthService;
        $this->sessionService = $sessionService;
    }

    /**
     * Redirect the user to the provider's authentication page.
     *
     * @param  string  $provider
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function redirectToProvider(string $provider): RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Obtain the user information from the provider.
     *
     * @param  string  $provider
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleProviderCallback(string $provider)
    {
        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            \Log::error('Social login callback error', ['provider' => $provider, 'error' => $e->getMessage()]);
            $frontend = config('app.frontend_url');
            return redirect(rtrim($frontend, '/') . '/login?error=oauth_failed');
        }

        $user = $this->socialAuthService->findOrCreateUser($socialUser, $provider);

        if (!$user) {
            \Log::error('Social login: failed to find or create user', ['provider' => $provider, 'email' => $socialUser->getEmail()]);
            $frontend = config('app.frontend_url');
            return redirect(rtrim($frontend, '/') . '/login?error=user_creation_failed');
        }

        // Create session and ensure it's saved
        $this->sessionService->createSession($user, true);
        request()->session()->regenerate();
        request()->session()->save();

        // Always redirect to frontend callback page
        // The frontend will handle routing based on user state (onboarding, dashboard, etc.)
        $frontend = config('app.frontend_url');
        $redirectUrl = rtrim($frontend, '/') . '/auth/callback';

        return redirect()->to($redirectUrl);
    }
}
