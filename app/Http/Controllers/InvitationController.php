<?php

namespace App\Http\Controllers;

class InvitationController extends Controller
{
    public function show(string $token)
    {
        return view('invitation.accept', ['token' => $token]);
    }
}
