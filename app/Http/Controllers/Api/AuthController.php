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
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function registerquizee(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => 'nullable|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'institution' => 'nullable|string',
            'grade_id' => 'nullable|exists:grades,id',
            'level_id' => 'nullable|exists:levels,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Prefer explicit name if provided, else combine first/last, else fallback to email
        $name = null;
        if ($request->filled('name')) {
            $name = $request->name;
        } else {
            $name = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? '')) ?: $request->email;
        }
        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quizee',
        ]);

        // Persist phone on user record if provided (user table may hold phone number used elsewhere)
        if ($request->filled('phone')) {
            try { $user->phone = $request->phone; $user->save(); } catch (\Exception $e) { /* ignore save errors */ }
        }

        // Derive first/last name for profile if not explicitly provided
        $qFirst = $request->first_name;
        $qLast = $request->last_name;
        if (empty($qFirst) && empty($qLast) && $request->filled('name')) {
            $parts = preg_split('/\s+/', trim($request->name));
            $qFirst = $parts[0] ?? null;
            $qLast = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
        }

        $quizee = Quizee::create([
            'user_id' => $user->id,
            'first_name' => $qFirst,
            'last_name' => $qLast,
            'institution' => $request->institution,
            'grade_id' => $request->grade_id,
            'level_id' => $request->level_id ?? null,
            'subjects' => $request->subjects ?? [],
        ]);

        // Assign default package subscription (idempotent). If an active, non-expired
        // subscription already exists, do nothing. Otherwise create/update and activate
        // using the model helper so duration logic is consistent.
        $defaultPackage = \App\Models\Package::where('is_default', true)->first();
        if ($defaultPackage) {
            $existingSub = \App\Models\Subscription::where('user_id', $user->id)->orderByDesc('created_at')->first();
            if (!($existingSub && $existingSub->status === 'active' && (is_null($existingSub->ends_at) || $existingSub->ends_at->gt(now())))) {
                $sub = \App\Models\Subscription::updateOrCreate([
                    'user_id' => $user->id,
                ], [
                    'package_id' => $defaultPackage->id,
                    'status' => 'active',
                    'gateway' => 'seed',
                    'gateway_meta' => null,
                ]);
                $sub->load('package');
                $sub->activate();
            }
        }
        return response()->json(['user' => $user, 'quizee' => $quizee], 201);
    }

    public function registerQuizMaster(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => 'nullable|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'institution' => 'nullable|string',
            'grade_id' => 'nullable|exists:grades,id',
            'level_id' => 'nullable|exists:levels,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Prefer explicit name if provided, else combine first/last, else fallback to email
        $name = null;
        if ($request->filled('name')) {
            $name = $request->name;
        } else {
            $name = trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? '')) ?: $request->email;
        }
        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quiz-master',
        ]);

        // Persist phone on user record if provided
        if ($request->filled('phone')) {
            try { $user->phone = $request->phone; $user->save(); } catch (\Exception $e) { /* ignore save errors */ }
        }

        // Derive first/last name for profile if not explicitly provided
        $mFirst = $request->first_name;
        $mLast = $request->last_name;
        if (empty($mFirst) && empty($mLast) && $request->filled('name')) {
            $parts = preg_split('/\s+/', trim($request->name));
            $mFirst = $parts[0] ?? null;
            $mLast = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
        }

        $quizMaster = QuizMaster::create([
            'user_id' => $user->id,
            'first_name' => $mFirst,
            'last_name' => $mLast,
            'institution' => $request->institution,
            'grade_id' => $request->grade_id,
            'level_id' => $request->level_id ?? null,
            'subjects' => $request->subjects ?? [],
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
        // Support "remember me" so clients can request a persistent login.
        $remember = $request->boolean('remember', false);
        // Log the login attempt for debugging (temporary)
        Log::info('Login attempt', ['email' => $request->input('email')]);
        // Additional debug: check whether a user exists and whether the password matches
        try {
            $dbgUser = User::where('email', $request->input('email'))->first();
            if ($dbgUser) {
                $pwMatch = \Illuminate\Support\Facades\Hash::check($request->input('password'), $dbgUser->password);
                Log::info('Login debug user found', ['email' => $dbgUser->email, 'pw_match' => $pwMatch]);
            } else {
                Log::info('Login debug user not found', ['email' => $request->input('email')]);
            }
        } catch (\Exception $ex) {
            Log::error('Login debug error', ['err' => $ex->getMessage()]);
        }
        if (! Auth::attempt($request->only('email', 'password'), $remember)) {
            Log::warning('Login failed', ['email' => $request->input('email')]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

    // Obtain the authenticated user and ensure affiliate and institutions relations are loaded
    $user = Auth::user();
    // Load affiliate relation and institutions so frontend receives institution data without a second request
    $user->loadMissing(['affiliate', 'institutions']);

    // Regenerate session id for security (uses the configured single session cookie)
    $request->session()->regenerate();

    return response()->json(['role' => $user->getAttribute('role'), 'user' => $user]);
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
