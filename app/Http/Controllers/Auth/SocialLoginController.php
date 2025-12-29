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
            return redirect('/login')->with('error', 'An error occurred while trying to log you in.');
        }

        $user = $this->socialAuthService->findOrCreateUser($socialUser, $provider);

        if (!$user) {
            return redirect('/login')->with('error', 'An error occurred while trying to log you in.');
        }

        $this->sessionService->createSession($user);

        if (!$user->is_profile_completed) {
            return redirect()->route('onboarding.profile');
        }

        return redirect()->intended('/dashboard');
    }
}
