<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\OnboardingService;

class UserController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->get('q');
        if (!$q) return response()->json(['users' => []]);

        $users = User::where('email', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json(['users' => $users]);
    }

    public function findByEmail(Request $request)
    {
        $email = $request->get('email');
        if (!$email) return response()->json(['message' => 'email required'], 400);

        $user = User::where('email', $email)->first(['id', 'name', 'email', 'avatar_url']);
        if (!$user) return response()->json(['message' => 'not found'], 404);
        return response()->json(['user' => $user]);
    }

    /**
     * Return paginated badges for authenticated user
     */
    public function badges(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false], 401);

        // If user has badges relation, return paginated list
        if (!method_exists($user, 'badges')) {
            return response()->json(['ok' => true, 'badges' => []]);
        }

        $perPage = max(1, (int)$request->get('per_page', 6));
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

        $validator = \Validator::make($data, $rules);
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
        if ($request->has('bio')) {
            $user->bio = $data['bio'];
        }
        if ($request->has('institution')) {
            $user->institution = $data['institution'];
        }

        $user->save();

        // Load relationships for full response
        $user->loadMissing(['quizeeProfile', 'quizMasterProfile', 'affiliate', 'institutions']);

        return response()->json($user);
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->all();

        $rules = [
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ];

        $validator = \Validator::make($data, $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!\Hash::check($data['current_password'], $user->password)) {
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

        $validator = \Validator::make($data, $rules);
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
