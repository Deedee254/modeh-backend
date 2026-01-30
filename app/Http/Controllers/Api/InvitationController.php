<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Minimal stub controller to satisfy route loading in dev.
 * Methods return simple JSON placeholders. This file is intentionally
 * lightweight and safe for development. Replace with the real
 * implementation when available.
 */
class InvitationController extends Controller
{
    public function show($token)
    {
        return response()->json(['ok' => true, 'token' => $token]);
    }

    public function register(Request $request)
    {
        return response()->json(['ok' => true, 'message' => 'register placeholder']);
    }

    public function claim(Request $request)
    {
        return response()->json(['ok' => true, 'message' => 'claim placeholder']);
    }

    public function validateToken(Request $request)
    {
        return response()->json(['ok' => true, 'valid' => false]);
    }
}
