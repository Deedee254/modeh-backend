<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Quizee;
use App\Models\QuizMaster;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function registerquizee(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $name = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? '')) ?: $request->email;
        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quizee',
        ]);

        $quizee = Quizee::create([
            'user_id' => $user->id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
        ]);

        return response()->json(['user' => $user, 'quizee' => $quizee], 201);
    }

    public function registerQuizMaster(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $name = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? '')) ?: $request->email;
        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quiz-master',
        ]);

        $quizMaster = QuizMaster::create([
            'user_id' => $user->id,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
        ]);

        return response()->json(['user' => $user, 'quizMaster' => $quizMaster], 201);
    }

    public function login(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Attempt to authenticate using session (cookie-based) auth
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Obtain the authenticated user
        $user = Auth::user();

        // Regenerate session id for security (uses the configured single session cookie)
        $request->session()->regenerate();

        return response()->json(['role' => $user->role, 'user' => $user]);
    }

    public function logout(Request $request)
    {
        // Determine which cookie name is currently in use so we can clear it
        $cookieName = config('session.cookie');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Expire the role-specific cookie on logout
        $response = response()->json(['message' => 'Logged out']);
        if ($cookieName) {
            $response->headers->clearCookie($cookieName);
        }

        return $response;
    }
}
