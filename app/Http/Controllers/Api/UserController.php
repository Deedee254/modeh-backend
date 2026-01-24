<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\OnboardingService;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Sync backend session for stateful requests (e.g. from the Nuxt frontend).
        // If the user is authenticated via Bearer token but the session is empty,
        // we establish the session so stateful features like Laravel Echo or
        // session-based preferences work correctly.
        if ($request->hasSession() && !Auth::guard('web')->check()) {
            Auth::guard('web')->login($user);
        }

        $cacheKey = "user_me_{$user->id}";

        // Add cache busting if user has been modified recently (avoid stale cache after updates)
        $userUpdatedAt = $user->updated_at ? $user->updated_at->timestamp : time();
        $cacheMinutes = max(1, 5 - ((time() - $userUpdatedAt) / 60));

        $userData = Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($user) {
            $relations = ['affiliate', 'institutions', 'onboarding'];

            if ($user->role === 'quiz-master') {
                $relations[] = 'quizMasterProfile.grade';
                $relations[] = 'quizMasterProfile.level';
                $relations[] = 'quizMasterProfile.institution';
                // NOTE: `subjectModels` is an accessor (not an Eloquent relation) on the profile model
                // and cannot be eager-loaded via ->with()/loadMissing(). Access the attribute directly
                // after loading the profile (it will be available when the model is serialized).
            } elseif ($user->role === 'quizee') {
                $relations[] = 'quizeeProfile.grade';
                $relations[] = 'quizeeProfile.level';
                $relations[] = 'quizeeProfile.institution';
                // See note above regarding subjectModels accessor.
            }

            $user->loadMissing($relations);
            return $user;
        });

        // Build response with headers for frontend validation
        // X-User-ID and X-User-Email allow frontend to detect JWT user_id mismatches
        return response()
            ->json(UserResource::make($userData))
            ->header('X-User-ID', (string)$user->id)
            ->header('X-User-Email', $user->email);
    }

    public function search(Request $request)
    {
        $q = $request->get('q');
        if (!$q)
            return response()->json(['users' => []]);

        $users = User::where('email', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(['users' => $users]);
    }

    public function findByEmail(Request $request)
    {
        $email = $request->get('email');
        if (!$email)
            return response()->json(['message' => 'email required'], 400);

        $user = User::where('email', $email)->first(['id', 'name', 'email', 'avatar_url']);
        if (!$user)
            return response()->json(['message' => 'not found'], 404);
        return response()->json(['user' => $user]);
    }

    /**
     * Return paginated badges for authenticated user
     */
    public function badges(Request $request)
    {
        $user = $request->user();
        if (!$user)
            return response()->json(['ok' => false], 401);

        // If user has badges relation, return paginated list
        if (!method_exists($user, 'badges')) {
            return response()->json(['ok' => true, 'badges' => []]);
        }

        $perPage = max(1, (int) $request->get('per_page', 6));
        $q = $user->badges()->latest('user_badges.created_at');
        $data = $q->paginate($perPage);

        $badges = $data->getCollection()->map(function ($b) {
            return ['id' => $b->id, 'title' => $b->name ?? $b->title ?? null, 'description' => $b->description ?? null, 'earned_at' => $b->pivot->earned_at ?? null];
        });

        return response()->json(['ok' => true, 'badges' => $badges, 'meta' => ['total' => $data->total(), 'per_page' => $data->perPage()]]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->all();

        $rules = [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'bio' => 'sometimes|nullable|string|max:1000',
            'institution' => 'sometimes|nullable|string',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $path = $file->store('avatars', 'public');
            $user->avatar_url = '/storage/' . $path;
        }

        // Only update fields that were actually provided
        if ($request->has('name')) {
            $user->name = $data['name'];
        }
        if ($request->has('email')) {
            $user->email = $data['email'];
        }
        if ($request->has('phone')) {
            $user->phone = $data['phone'];
        }
        // Save bio on users table for all roles
        if ($request->has('bio')) {
            $user->bio = $data['bio'];
        }
        if ($request->has('institution')) {
            $user->institution = $data['institution'];
        }

        $user->save();

        // Clear me cache to ensure fresh data is returned on next /me request
        Cache::forget("user_me_{$user->id}");

        // Refresh the user model from database to reflect all changes
        $user = $user->fresh();

        // Load relationships for full response
        $user->loadMissing(['quizeeProfile', 'quizMasterProfile', 'affiliate', 'institutions']);

        return UserResource::make($user);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->all();

        $rules = [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 403);
        }

        $user->password = $data['password'];
        $user->save();

        return response()->json(['message' => 'Password updated']);
    }

    /**
     * Store the user's theme preference in session only.
     * This does not persist to the database — it's kept in the session.
     */
    public function setTheme(Request $request)
    {
        $data = $request->all();

        $rules = [
            'theme' => 'required|string',
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $theme = $data['theme'];

        // Store in session only
        $request->session()->put('theme', $theme);

        return response()->json(['message' => 'Theme saved', 'theme' => $theme]);
    }

    /**
     * Return the theme value stored in session (if any)
     */
    public function getTheme(Request $request)
    {
        $theme = $request->session()->get('theme', null);
        return response()->json(['theme' => $theme]);
    }
}
