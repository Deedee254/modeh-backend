<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Quizee;
use App\Models\QuizMaster;
use App\Models\User;
use App\Models\Institution;
use App\Models\Affiliate;
use App\Models\AffiliateReferral;
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
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'institution' => 'nullable|string',
            'level_id' => 'required|exists:levels,id',
            'grade_id' => 'required|exists:grades,id',
            'subjects' => 'required|array|min:1',
            'subjects.*' => 'exists:subjects,id',
            'parentEmail' => ['nullable', 'email']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Create user with provided name
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quizee',
        ]);

        // Create quizee profile with institution and required taxonomy
        $quizee = Quizee::create([
            'user_id' => $user->id,
            'institution' => $request->institution,
            'level_id' => $request->level_id,
            'grade_id' => $request->grade_id,
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

        // Handle affiliate referral attribution
        // Check for ?ref=CODE query parameter or ref in request body
        $referralCode = $request->input('ref') ?? $request->query('ref');
        if (!empty($referralCode)) {
            try {
                $affiliate = Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    // Create referral record linking this new user to the affiliate
                    AffiliateReferral::create([
                        'affiliate_id' => $affiliate->id,
                        'user_id' => $user->id,
                        'type' => 'signup',
                        'earnings' => 0, // Earnings will be calculated after purchases
                        'status' => 'active',
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to create affiliate referral', ['error' => $e->getMessage()]);
                // Continue signup even if referral tracking fails
            }
        }

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        return response()->json(['user' => $user, 'quizee' => $quizee, 'message' => 'Registration successful. Please verify your email.'], 201);
    }

    public function registerQuizMaster(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'institution' => 'nullable|string',
            'level_id' => 'required|exists:levels,id',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'required|array|min:1',
            'subjects.*' => 'exists:subjects,id',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Create user with provided name
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'quiz-master',
        ]);

        // Persist phone on user record if provided
        if ($request->filled('phone')) {
            try { $user->phone = $request->phone; $user->save(); } catch (\Exception $e) { /* ignore save errors */ }
        }

        // Create quiz master profile with institution and required taxonomy
        $quizMaster = QuizMaster::create([
            'user_id' => $user->id,
            'institution' => $request->institution,
            'level_id' => $request->level_id,
            'grade_id' => $request->grade_id,
            'subjects' => $request->subjects ?? [],
        ]);

        // Handle affiliate referral attribution
        // Check for ?ref=CODE query parameter or ref in request body
        $referralCode = $request->input('ref') ?? $request->query('ref');
        if (!empty($referralCode)) {
            try {
                $affiliate = Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    // Create referral record linking this new user to the affiliate
                    AffiliateReferral::create([
                        'affiliate_id' => $affiliate->id,
                        'user_id' => $user->id,
                        'type' => 'signup',
                        'earnings' => 0, // Earnings will be calculated after purchases
                        'status' => 'active',
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to create affiliate referral', ['error' => $e->getMessage()]);
                // Continue signup even if referral tracking fails
            }
        }

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        return response()->json(['user' => $user, 'quizMaster' => $quizMaster, 'message' => 'Registration successful. Please verify your email.'], 201);
    }

    public function registerInstitutionManager(Request $request)
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'institution_id' => 'nullable|exists:institutions,id',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'institution-manager',
            'phone' => $request->phone,
        ]);

        // If institution_id provided, attach user to institution with manager role
        // Otherwise user can create/join institutions later in onboarding
        if ($request->filled('institution_id')) {
            $institution = Institution::find($request->institution_id);
            if ($institution) {
                $institution->users()->attach($user->id, [
                    'role' => 'institution-manager',
                    'status' => 'active',
                    'invited_by' => null,
                ]);
            }
        }

        return response()->json([
            'user' => $user,
            'message' => 'Institution Manager registration successful. Complete your profile to continue.'
        ], 201);
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
