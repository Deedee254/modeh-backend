<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function show(string $token)
    {
        return view('invitation.accept', ['token' => $token]);
    }
}
