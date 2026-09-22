<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminAdvertiserController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', function ($request, $next) {
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized. Admin access required.'], 403);
            }

            return $next($request);
        }]);
    }

    public function index(): JsonResponse
    {
        $advertisers = User::query()
            ->where('role', 'advertiser')
            ->withCount('ads')
            ->withSum('ads as impressions_total', 'impressions_count')
            ->withSum('ads as clicks_total', 'clicks_count')
            ->latest()
            ->get(['id', 'name', 'email', 'is_profile_completed', 'created_at']);

        return response()->json([
            'ok' => true,
            'advertisers' => $advertisers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'nullable|string|min:8|max:255',
        ]);

        $temporaryPassword = $validated['password'] ?? Str::password(14);
        $advertiser = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($temporaryPassword),
            'role' => 'advertiser',
            'is_profile_completed' => true,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Ad manager account created successfully.',
            'advertiser' => $advertiser->only(['id', 'name', 'email', 'role', 'created_at']),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'advertiser', 404);

        $validated = $request->validate([
            'password' => 'nullable|string|min:8|max:255',
        ]);

        $temporaryPassword = $validated['password'] ?? Str::password(14);
        $user->update(['password' => Hash::make($temporaryPassword)]);

        return response()->json([
            'ok' => true,
            'message' => 'Ad manager password reset successfully.',
            'temporary_password' => $temporaryPassword,
        ]);
    }
}