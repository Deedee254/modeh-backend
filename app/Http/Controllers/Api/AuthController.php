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
use App\Models\Package;
use Illuminate\Support\Carbon;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function registerquizee(Request $request)
    {
        // Check if user already exists (for OAuth + same email case)
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            // Revoke old tokens and create a fresh one for the login attempt
            $existingUser->tokens()->delete();
            return response()->json([
                'message' => 'User already exists',
                'user' => $existingUser,
                'isNewUser' => false,
                'token' => $existingUser->createToken('auth')->plainTextToken
            ], 409);
        }

        // OAuth users don't need password, email/password users do
        $isOAuth = !empty($request->input('social_id'));
        
        $v = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => $isOAuth ? 'nullable' : 'required|min:6',
            'institution' => 'nullable|string',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\\s\\.]?[0-9]{3}[-\\s\\.]?[0-9]{4,6}$/'],
            'bio' => 'nullable|string|max:500',
            'level_id' => 'nullable|exists:levels,id',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'nullable|array',
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
            'password' => $request->password ? Hash::make($request->password) : Hash::make(Str::random(40)),
            'role' => 'quizee',
            'phone' => $request->phone,
            'social_id' => $request->input('social_id'),
            'social_provider' => $request->input('social_provider'),
        ]);

        // Create quizee profile with institution, bio, and required taxonomy
        $quizee = Quizee::create([
            'user_id' => $user->id,
            'institution' => $request->institution,
            'profile' => $request->bio,
            'level_id' => $request->level_id,
            'grade_id' => $request->grade_id,
            'subjects' => $request->subjects ?? [],
        ]);

        // Assign default package subscription to quizee (idempotent)
        try {
            $defaultPackage = Package::where('is_default', true)->first();
            if ($defaultPackage) {
                $existingSub = Subscription::where('user_id', $user->id)->orderByDesc('created_at')->first();
                $endsAtValid = false;
                if ($existingSub && $existingSub->ends_at) {
                    // Ensure we compare using Carbon instance to avoid DateTimeInterface::gt() errors
                    $endsAtValid = Carbon::parse($existingSub->ends_at)->gt(now());
                }
                if (!($existingSub && $existingSub->status === 'active' && (is_null($existingSub->ends_at) || $endsAtValid))) {
                    Subscription::updateOrCreate([
                        'user_id' => $user->id,
                    ], [
                        'package_id' => $defaultPackage->id,
                        'status' => 'active',
                        'gateway' => 'seed',
                        'gateway_meta' => null,
                        'started_at' => now(),
                        'ends_at' => now()->addDays($defaultPackage->duration_days ?? 30),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to assign default subscription to quizee', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            // Continue registration even if subscription assignment fails
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

        // CRITICAL: Establish session to auto-login the user after registration
        // This requires the 'web' middleware on the registration route
        // Regenerate session to prevent session fixation attacks
        $request->session()->regenerate();
        
        // Authenticate the user (establishes session)
        Auth::login($user, remember: false);

        // Create a personal access token for API access (same as login endpoint)
        $token = $user->createToken('nuxt-auth')->plainTextToken;

        // Load relations for richer response
        $user->loadMissing(['quizeeProfile.grade', 'quizeeProfile.level', 'institutions', 'affiliate', 'onboarding']);

        Log::info('User registered and auto-logged in', ['user_id' => $user->id, 'email' => $user->email, 'role' => $user->role]);

        // Return response in the same format as login endpoint so Nuxt-Auth can process it
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('role') ?? 'user',
            'avatar' => $user->getAttribute('avatar'),
            'image' => $user->getAttribute('avatar'),
            'user' => $user,
            'quizee' => $quizee,
            'message' => 'Registration successful. You are now logged in.',
            'token' => $token
        ], 201);
    }

    public function registerQuizMaster(Request $request)
    {
        // Check if user already exists (for OAuth + same email case)
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            // Revoke old tokens and create a fresh one for the login attempt
            $existingUser->tokens()->delete();
            return response()->json([
                'message' => 'User already exists',
                'user' => $existingUser,
                'isNewUser' => false,
                'token' => $existingUser->createToken('auth')->plainTextToken
            ], 409);
        }

        // OAuth users don't need password, email/password users do
        $isOAuth = !empty($request->input('social_id'));
        
        $v = Validator::make($request->all(), [
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => $isOAuth ? 'nullable' : 'required|min:6',
            'institution' => 'nullable|string',
            'level_id' => 'nullable|exists:levels,id',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'bio' => 'nullable|string|max:500',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Create user with provided name and optional phone
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password ? Hash::make($request->password) : Hash::make(Str::random(40)),
            'role' => 'quiz-master',
            'phone' => $request->phone,
            'social_id' => $request->input('social_id'),
            'social_provider' => $request->input('social_provider'),
        ]);

        // Create quiz master profile with institution, bio, and required taxonomy
        $quizMaster = QuizMaster::create([
            'user_id' => $user->id,
            'institution' => $request->institution,
            'level_id' => $request->level_id,
            'grade_id' => $request->grade_id,
            'subjects' => $request->subjects ?? [],
            'bio' => $request->bio,
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

        // CRITICAL: Establish session to auto-login the user after registration
        // This requires the 'web' middleware on the registration route
        // Regenerate session to prevent session fixation attacks
        $request->session()->regenerate();
        
        // Authenticate the user (establishes session)
        Auth::login($user, remember: false);

        // Create a personal access token for API access (same as login endpoint)
        $token = $user->createToken('nuxt-auth')->plainTextToken;

        // Load relations for richer response
        $user->loadMissing(['quizMasterProfile.grade', 'quizMasterProfile.level', 'institutions', 'affiliate', 'onboarding']);

        Log::info('Quiz Master registered and auto-logged in', ['user_id' => $user->id, 'email' => $user->email, 'role' => $user->role]);

        // Return response in the same format as login endpoint so Nuxt-Auth can process it
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('role') ?? 'user',
            'avatar' => $user->getAttribute('avatar'),
            'image' => $user->getAttribute('avatar'),
            'user' => $user,
            'quizMaster' => $quizMaster,
            'message' => 'Registration successful. You are now logged in.',
            'token' => $token
        ], 201);
    }

    public function registerInstitutionManager(Request $request)
    {
        // Check if user already exists (for OAuth + same email case)
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            // Revoke old tokens and create a fresh one for the login attempt
            $existingUser->tokens()->delete();
            return response()->json([
                'message' => 'User already exists',
                'user' => $existingUser,
                'isNewUser' => false,
                'token' => $existingUser->createToken('auth')->plainTextToken
            ], 409);
        }

        // OAuth users don't need password, email/password users do
        $isOAuth = !empty($request->input('social_id'));
        
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => $isOAuth ? 'nullable' : 'required|min:6',
            'institution_id' => 'nullable|exists:institutions,id',
            'phone' => ['nullable', 'regex:/^[+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/']
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password ? Hash::make($request->password) : Hash::make(Str::random(40)),
            'role' => 'institution-manager',
            'phone' => $request->phone,
            'social_id' => $request->input('social_id'),
            'social_provider' => $request->input('social_provider'),
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

        // Handle affiliate referral attribution for institution manager signup as well
        $referralCode = $request->input('ref') ?? $request->query('ref');
        if (!empty($referralCode)) {
            try {
                $affiliate = Affiliate::where('referral_code', $referralCode)->first();
                if ($affiliate) {
                    AffiliateReferral::create([
                        'affiliate_id' => $affiliate->id,
                        'user_id' => $user->id,
                        'type' => 'signup',
                        'earnings' => 0,
                        'status' => 'active',
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to create affiliate referral', ['error' => $e->getMessage()]);
            }
        }

        // CRITICAL: Establish session to auto-login the user after registration
        // This requires the 'web' middleware on the registration route
        // Regenerate session to prevent session fixation attacks
        $request->session()->regenerate();
        
        // Authenticate the user (establishes session)
        Auth::login($user, remember: false);

        // Create a personal access token for API access (same as login endpoint)
        $token = $user->createToken('nuxt-auth')->plainTextToken;

        // Load relations for richer response
        $user->loadMissing(['institutions', 'affiliate', 'onboarding']);

        Log::info('Institution Manager registered and auto-logged in', ['user_id' => $user->id, 'email' => $user->email, 'role' => $user->role]);

        // Return response in the same format as login endpoint so Nuxt-Auth can process it
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('role') ?? 'user',
            'avatar' => $user->getAttribute('avatar'),
            'image' => $user->getAttribute('avatar'),
            'user' => $user,
            'message' => 'Registration successful. You are now logged in.',
            'token' => $token
        ], 201);
    }

    /**
     * Sync social login from Nuxt-Auth.
     * POST /api/auth/social-sync
     * 
     * Returns: isNewUser flag to indicate if user just registered or existing
     */
    public function socialSync(Request $request, \App\Services\SocialAuthService $socialAuthService)
    {
        $v = Validator::make($request->all(), [
            'provider' => 'required|string',
            'id' => 'required|string',
            'email' => 'required|email',
            'name' => 'nullable|string',
            'image' => 'nullable|string',
            'token' => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        // Check if user already exists by email (simplifies new user detection)
        $isNewUser = !User::where('email', $request->email)->exists();

        // Diagnostic logging: capture incoming payload to help debug production failures
        try {
            Log::info('socialSync incoming payload', ['provider' => $request->input('provider'), 'payload' => $request->all(), 'ip' => $request->ip()]);
        } catch (\Throwable $e) {
            // If logging the payload fails for any reason, still continue — we don't want to block auth flow
            Log::warning('Failed to log socialSync payload: ' . $e->getMessage());
        }

        // Create a mock object that mimics Socialite user interface for compatibility
        $socialUser = new class($request->all()) {
            private $data;
            public function __construct($data) { $this->data = $data; }
            public function getId() { return $this->data['id']; }
            public function getName() { return $this->data['name'] ?? explode('@', $this->data['email'])[0]; }
            public function getEmail() { return $this->data['email']; }
            public function getAvatar() { return $this->data['image'] ?? null; }
            public $token;
            public $refreshToken = null;
            public $expiresIn = null;
        };
        $socialUser->token = $request->input('token');

        // Attempt to find-or-create the user via SocialAuthService with diagnostics
        try {
            $user = $socialAuthService->findOrCreateUser($socialUser, $request->input('provider'));
        } catch (\Throwable $e) {
            Log::error('socialSync failed in SocialAuthService', [
                'provider' => $request->input('provider'),
                'payload' => $request->all(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Don't return the exception message to clients — log it for debugging
            return response()->json(['message' => 'Failed to sync social user'], 500);
        }

        if (!$user) {
            Log::error('socialSync returned null user', ['provider' => $request->input('provider'), 'payload' => $request->all()]);
            return response()->json(['message' => 'Failed to sync social user'], 500);
        }

        // For server-to-server social sync we avoid relying on the HTTP
        // session (no CSRF or cookies). Create a personal access token and
        // return it — the frontend will use it for authenticated API calls.
        $user = User::find($user->id);
        
        // Revoke all old tokens to prevent token accumulation
        $user->tokens()->delete();
        
        $user->loadMissing(['affiliate', 'institutions', 'onboarding']);

        // Create a personal access token for the user (stateless)
        $token = $user->createToken('nuxt-auth')->plainTextToken;

        // Return user data in Nuxt-Auth compatible format with isNewUser flag
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('role'),
            'avatar' => $user->getAttribute('avatar'),
            'image' => $user->getAttribute('avatar'),
            'user' => $user,
            'token' => $token,
            'isNewUser' => $isNewUser,  // ← Simplifies redirect logic
            'requires_onboarding' => empty($user->role) || !$user->is_profile_completed
        ]);
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

        // Get credentials
        $credentials = $request->only('email', 'password');
        
        // If there's an active session from a previous user, ensure it's fully cleared
        if ($request->user()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        // Regenerate session before login to prevent session fixation
        $request->session()->regenerate();

        // Attempt to authenticate with persistent login (remember = true)
        if (! Auth::attempt($credentials, true)) {
            Log::warning('Login attempt failed', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Load user data explicitly to ensure it's the correct User model with HasApiTokens trait
        $user = User::find(Auth::id());
        
        // Revoke all old tokens to prevent token accumulation and potential leaks
        $user->tokens()->delete();
        
        $user->loadMissing(['affiliate', 'institutions', 'onboarding']);

        // Create a personal access token for the session
        $token = $user->createToken('nuxt-auth')->plainTextToken;

        Log::info('User logged in', ['user_id' => $user->id, 'email' => $user->email, 'role' => $user->role]);

        // Return user data in format expected by Nuxt-Auth
        // The NuxtAuthHandler's authorize callback expects: { id, name, email, role, image, ... }
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getAttribute('role') ?? 'user',
            'avatar' => $user->getAttribute('avatar'),
            'image' => $user->getAttribute('avatar'),
            'user' => $user,
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke the current API token to prevent its reuse
        $user = $request->user();
        if ($user) {
            $user->currentAccessToken()?->delete();
        }
        
        // Fully clear authentication
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        
        // Create a new session with a new token (prevents session fixation after logout)
        $request->session()->regenerate();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get a fresh CSRF token. Call this before login to prepare session.
     * GET /api/csrf-token or GET /sanctum/csrf-cookie or GET /api/sanctum/csrf-cookie
     * 
     * This endpoint:
     * 1. Initializes session if needed
     * 2. Generates/retrieves CSRF token
     * 3. Sets XSRF-TOKEN cookie explicitly
     * 4. Returns proper CORS headers for credentials
     * 5. Returns token in JSON body
     * 
     * Frontend Usage:
     * - Called by useApi.ensureCsrf() before any authenticated requests
     * - Expects credentials: 'include' in fetch to capture cookies
     * - Polls document.cookie for XSRF-TOKEN appearance (2s timeout)
     * 
     * Common Issues:
     * - 404: Route not configured in routes/api.php (must add /sanctum/csrf-cookie)
     * - CORS error: HandleCors middleware not applied to this route
     * - Cookie not set: Check SESSION_SECURE_COOKIE, SESSION_SAME_SITE env vars
     */
    public function getCsrfToken(Request $request)
    {
        // Ensure session is started (if not already by middleware)
        $request->session()->start();
        
        // Get or create CSRF token for this session
        $token = $request->session()->token();
        
        // Log for debugging - helps identify CORS or routing issues
        Log::debug('CSRF token endpoint called', [
            'token_prefix' => substr($token, 0, 10),
            'session_id' => $request->session()->getId(),
            'host' => $request->getHost(),
            'origin' => $request->header('Origin'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ]);
        
        // Create response with token in body for debugging
        $response = response()->json([
            'token' => $token,
            'session_id' => $request->session()->getId()
        ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
         ->header('Pragma', 'no-cache')
         ->header('Expires', '0')
         // Ensure credentials are allowed (set by HandleCors, but explicit for clarity)
         ->header('Access-Control-Allow-Credentials', 'true');
        
        // Explicitly set XSRF-TOKEN cookie so it's available to frontend JavaScript
        // Use Laravel's cookie() helper which respects config/session.php settings
        // For localhost dev, an empty domain means host-only (works with both localhost and 127.0.0.1)
        $cookie = cookie(
            'XSRF-TOKEN',        // cookie name (unencrypted - frontend needs to read it)
            $token,              // cookie value
            (int)(env('SESSION_LIFETIME', 525600) / 60), // convert minutes to hours
            '/',                 // path - root so all routes can access
            '',                  // domain - empty = host-only, works with current origin
            (bool) env('SESSION_SECURE_COOKIE', false),  // secure flag - use env setting
            false                // httpOnly - MUST be false so JavaScript can read it for CSRF headers
        );
        
        return $response->cookie($cookie);
    }

    /**
     * Public endpoint: check whether an email address has been verified.
     * GET /api/auth/verify-status?email=...
     */
    public function verifyStatus(Request $request)
    {
        $email = $request->query('email');
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['error' => 'invalid_email'], 400);
        }

        $user = User::where('email', $email)->first();
        if (! $user) {
            return response()->json(['exists' => false, 'verified' => false], 200);
        }

        return response()->json([
            'exists' => true,
            'verified' => (bool) $user->hasVerifiedEmail(),
            'email' => $user->email,
        ], 200);
    }

    /**
     * Consume a frontend-submitted token and mark the user's email as verified.
     * POST /api/auth/verify-email { token }
     */
    public function verifyEmail(Request $request)
    {
        $token = $request->input('token');
        $ftoken = $request->input('ftoken');
        if (! $token || ! is_string($token)) {
            return response()->json(['error' => 'invalid_token'], 400);
        }

        $cacheKey = 'email_verification_token:' . $token;
        $payload = Cache::pull($cacheKey);
        if (! $payload || ! is_array($payload) || empty($payload['id']) || empty($payload['hash'])) {
            return response()->json(['error' => 'token_not_found_or_expired'], 410);
        }

        $user = User::find($payload['id']);
        if (! $user) return response()->json(['error' => 'user_not_found'], 404);

        // validate the hash matches expected sha1 of email
        if (! hash_equals((string) $payload['hash'], sha1($user->getEmailForVerification()))) {
            return response()->json(['error' => 'invalid_hash'], 400);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        // If a frontend invite token was provided, attempt to resolve the invitation mapping
        // and either accept it now (if the request is authenticated) or return the invite token
        // so the frontend can save it for post-login processing.
        if ($ftoken && is_string($ftoken)) {
            $cacheKey2 = 'invite_frontend_token:' . $ftoken;
            $map = Cache::pull($cacheKey2);
            if ($map && is_array($map) && !empty($map['invitation_token'])) {
                $inviteToken = $map['invitation_token'];
                $institutionId = $map['institution_id'] ?? null;
                // If user is authenticated, accept the invite immediately
                $authUser = $request->user();
                if ($authUser) {
                    try {
                        $invitation = DB::table('institution_user')
                            ->where('invitation_token', $inviteToken)
                            ->where('institution_id', $institutionId)
                            ->first();
                        if ($invitation) {
                            DB::table('institution_user')
                                ->where('id', $invitation->id)
                                ->update([
                                    'user_id' => $authUser->id,
                                    'invitation_status' => 'active',
                                    'status' => 'active',
                                    'invitation_token' => null,
                                    'invitation_expires_at' => null,
                                    'updated_at' => now()
                                ]);
                            // Attempt to assign subscription seat if applicable
                            try {
                                $institution = Institution::find($institutionId);
                                if ($institution) {
                                    $activeSub = $institution->activeSubscription();
                                    if ($activeSub && $activeSub->package) {
                                        $activeSub->assignUser($authUser->id, $authUser->id);
                                    }
                                }
                            } catch (\Throwable $_) { /* ignore */ }

                            return response()->json(['verified' => true, 'email' => $user->email, 'invite_accepted' => true]);
                        }
                    } catch (\Throwable $_) {
                        // ignore and fall through to returning invite token
                    }
                }

                // Not authenticated or acceptance failed: return invite token so frontend can save intent
                return response()->json(['verified' => true, 'email' => $user->email, 'invite_token' => $inviteToken]);
            }
        }

        return response()->json(['verified' => true, 'email' => $user->email]);
    }

    /**
     * Handle forgot password requests.
     * POST /api/auth/forgot-password { email }
     */
    public function forgotPassword(Request $request)
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $token = Str::random(64);
        
        $cacheKey = 'password_reset_token:' . $token;
        Cache::put($cacheKey, [
            'email' => $user->email,
            'user_id' => $user->id,
        ], now()->addHour());

        try {
            $resetUrl = '/reset-password/' . $token . '?email=' . urlencode($user->email);
            Mail::send('emails.password-reset', ['user' => $user, 'resetUrl' => $resetUrl, 'resetToken' => $token], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Reset Your Password');
            });
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', ['email' => $user->email, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to send reset email. Please try again later.'], 500);
        }

        return response()->json(['message' => 'Password reset link sent to your email']);
    }

    /**
     * Handle password reset.
     * POST /api/auth/reset-password { token, email, password, password_confirmation }
     */
    public function resetPassword(Request $request)
    {
        $v = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $cacheKey = 'password_reset_token:' . $request->token;
        $payload = Cache::get($cacheKey);
        
        if (!$payload || !is_array($payload) || empty($payload['email'])) {
            return response()->json(['message' => 'Invalid or expired reset token'], 400);
        }

        if ($payload['email'] !== $request->email) {
            return response()->json(['message' => 'Token does not match email'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        // CRITICAL: Invalidate the reset token immediately after use to prevent reuse attacks
        Cache::forget($cacheKey);
        
        // Also revoke all existing tokens to force re-login with new password
        $user->tokens()->delete();

        try {
            Mail::send('emails.password-reset-confirmed', ['user' => $user], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Your Password Has Been Reset');
            });
        } catch (\Exception $e) {
            Log::warning('Failed to send password reset confirmation email', ['email' => $user->email, 'error' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Password reset successfully']);
    }
}
