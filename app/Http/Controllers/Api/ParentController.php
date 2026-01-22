<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ParentUser;
use App\Models\QuizeeInvitation;
use App\Models\Quizee;
use App\Models\Subscription;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ParentController extends Controller
{
    public function register(Request $request)
    {
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            return response()->json([
                'message' => 'User already exists',
            ], 409);
        }

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
            'occupation' => 'nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'parent',
                'phone' => $request->phone,
                'is_profile_completed' => true,
            ]);

            $parentProfile = ParentUser::create([
                'user_id' => $user->id,
                'occupation' => $request->occupation,
                'phone' => $request->phone,
                'is_active' => true,
            ]);

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

            Log::info('Parent registered and auto-logged in', ['user_id' => $user->id, 'email' => $user->email, 'role' => $user->role]);

            // Return response in the same format as login endpoint so Nuxt-Auth can process it
            return response()->json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getAttribute('role') ?? 'user',
                'avatar' => $user->getAttribute('avatar'),
                'image' => $user->getAttribute('avatar'),
                'user' => $user,
                'parent' => $parentProfile,
                'message' => 'Registration successful. You are now logged in.',
                'token' => $token
            ], 201);
        } catch (\Exception $e) {
            Log::error('Parent registration failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Registration failed'], 500);
        }
    }

    public function dashboard()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $quizees = $parent->quizees()->with(['user', 'quizMaster'])->get();
        $pendingInvitations = $parent->pendingInvitations()->get();

        $dashboardData = [
            'parent_id' => $parent->id,
            'quizees_count' => $quizees->count(),
            'pending_invitations_count' => $pendingInvitations->count(),
            'quizees' => $quizees,
            'pending_invitations' => $pendingInvitations,
        ];

        return response()->json($dashboardData, 200);
    }

    public function inviteQuizee(Request $request)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $v = Validator::make($request->all(), [
            'quizee_email' => 'required|email',
            'quizee_name' => 'nullable|string|max:255',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        try {
            $existingInvitation = QuizeeInvitation::where('parent_id', $parent->id)
                ->where('student_email', $request->quizee_email)
                ->where('status', 'pending')
                ->first();

            if ($existingInvitation && !$existingInvitation->isExpired()) {
                return response()->json([
                    'message' => 'Invitation already sent to this email',
                    'invitation' => $existingInvitation,
                ], 409);
            }

            $invitation = QuizeeInvitation::create([
                'parent_id' => $parent->id,
                'student_email' => $request->quizee_email,
                'student_name' => $request->quizee_name,
                'token' => Str::random(32),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            return response()->json([
                'message' => 'Quizee invitation sent successfully',
                'invitation' => $invitation,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Quizee invitation failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to send invitation'], 500);
        }
    }

    public function getQuizees()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $quizees = $parent->quizees()
            ->with(['user', 'grade', 'level'])
            ->get()
            ->map(function ($quizee) {
                return [
                    'id' => $quizee->id,
                    'name' => $quizee->user->name,
                    'email' => $quizee->user->email,
                    'grade' => $quizee->grade,
                    'level' => $quizee->level,
                    'points' => $quizee->points,
                    'connected_at' => $quizee->pivot->connected_at,
                ];
            });

        return response()->json([
            'quizees' => $quizees,
            'count' => $quizees->count(),
        ], 200);
    }

    public function getQuizeeAnalytics($quizeeId)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $quizee = $parent->quizees()->find($quizeeId);
        if (!$quizee) {
            return response()->json(['message' => 'Quizee not found'], 404);
        }

        $quizAttempts = $quizee->quizAttempts()
            ->with('quiz')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $totalAttempts = $quizee->quizAttempts()->count();
        $totalScore = $quizee->quizAttempts()->sum('score');
        $averageScore = $totalAttempts > 0 ? $totalScore / $totalAttempts : 0;

        $analyticsData = [
            'quizee_id' => $quizee->id,
            'quizee_name' => $quizee->user->name,
            'total_points' => $quizee->points,
            'current_streak' => $quizee->current_streak,
            'longest_streak' => $quizee->longest_streak,
            'total_attempts' => $totalAttempts,
            'average_score' => round($averageScore, 2),
            'recent_attempts' => $quizAttempts,
        ];

        return response()->json($analyticsData, 200);
    }

    public function removeQuizee($quizeeId)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $quizee = $parent->quizees()->find($quizeeId);
        if (!$quizee) {
            return response()->json(['message' => 'Quizee not found'], 404);
        }

        try {
            $parent->quizees()->detach($quizeeId);
            return response()->json(['message' => 'Quizee removed successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Failed to remove quizee', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to remove quizee'], 500);
        }
    }

    public function manageSubscription(Request $request, $quizeeId)
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $quizee = $parent->quizees()->find($quizeeId);
        if (!$quizee) {
            return response()->json(['message' => 'Quizee not found'], 404);
        }

        $quizeeUser = $quizee->user;

        $v = Validator::make($request->all(), [
            'package_id' => 'required|exists:packages,id',
        ]);

        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        try {
            $package = Package::find($request->package_id);

            $subscription = Subscription::create([
                'user_id' => $quizeeUser->id,
                'owner_type' => 'App\Models\ParentUser',
                'owner_id' => $parent->id,
                'package_id' => $package->id,
                'status' => 'pending',
                'gateway' => 'manual',
                'started_at' => now(),
                'ends_at' => now()->addDays($package->duration_days ?? 30),
            ]);

            return response()->json([
                'message' => 'Subscription created successfully',
                'subscription' => $subscription,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create subscription', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to create subscription'], 500);
        }
    }

    public function getSubscriptions()
    {
        $user = Auth::user();
        if (!$user || $user->role !== 'parent') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $parent = ParentUser::where('user_id', $user->id)->first();
        if (!$parent) {
            return response()->json(['message' => 'Parent profile not found'], 404);
        }

        $subscriptions = Subscription::where('owner_type', 'App\Models\ParentUser')
            ->where('owner_id', $parent->id)
            ->with('package')
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ], 200);
    }
}
