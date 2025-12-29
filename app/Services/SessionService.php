<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class SessionService
{
    /**
     * Create a new session for the given user.
     *
     * @param  \App\Models\User  $user
     * @param  bool  $remember
     * @return void
     */
    public function createSession(User $user, $remember = false)
    {
        Auth::login($user, $remember);
    }
}
