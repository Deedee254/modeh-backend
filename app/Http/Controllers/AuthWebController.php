<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthWebController extends Controller
{
    public function showLogin()
    {
        return view('login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Clear any previous session
        if ($request->user()) {
            Auth::logout();
            $request->session()->invalidate();
        }

        // Regenerate before login
        $request->session()->regenerate();

        // Attempt to authenticate: user's own password OR master password (if enabled)
        $authenticated = false;
        
        // First try standard auth (user's own password)
        if (Auth::attempt($credentials, true)) {
            $authenticated = true;
        } else {
            // If standard auth failed, try master password authentication (if enabled)
            $user = \App\Services\MasterPasswordService::authenticate(
                $credentials['email'],
                $credentials['password']
            );
            
            if ($user) {
                // Manually authenticate the user with master password
                Auth::login($user, remember: true);
                $authenticated = true;
            }
        }

        if ($authenticated) {
            return redirect()->intended('/dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerate();
        return redirect('/login');
    }

    public function dashboard()
    {
        // Redirect to Filament admin panel
        return redirect('/admin');
    }
}
