<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

/**
 * Invitation Controller
 * 
 * Handles referral and signup invitations with token-based validation.
 * Supports viewing invitation details, registering new users via invitation,
 * and claiming/accepting invitations for existing users.
 */
class InvitationController extends Controller
{
    /**
     * Get invitation details by token (public endpoint)
     * 
     * @param string $token
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $token)
    {
        $invitation = Invitation::findByToken($token);

        if (!$invitation) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'invitation' => $invitation->getPublicDetails()
        ]);
    }

    /**
     * Register a new user via invitation (unauthenticated)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', Password::defaults()],
        ]);

        $invitation = Invitation::findByToken($validated['token']);

        if (!$invitation) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        // Email must match the invitation
        if ($invitation->email !== $validated['email']) {
            return response()->json([
                'ok' => false,
                'message' => 'Email does not match invitation'
            ], 422);
        }

        try {
            // Create the new user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Accept the invitation
            $invitation->accept($user);

            // Log the registration
            Log::info('User registered via invitation', [
                'user_id' => $user->id,
                'email' => $user->email,
                'invited_by' => $invitation->inviter_id
            ]);

            // Generate authentication token
            $token = $user->createToken('api-token')->plainTextToken;

            return response()->json([
                'ok' => true,
                'message' => 'Successfully registered',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'token' => $token
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Registration via invitation failed', [
                'error' => $e->getMessage(),
                'token' => $validated['token']
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Registration failed'
            ], 500);
        }
    }

    /**
     * Claim/accept an invitation for an authenticated user
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function claim(Request $request)
    {
        $validated = $request->validate([
            'token' => 'required|string',
        ]);

        /** @var User $user */
        $user = $request->user();

        $invitation = Invitation::findByToken($validated['token']);

        if (!$invitation) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired invitation'
            ], 404);
        }

        try {
            // Accept the invitation
            $invitation->accept($user);

            // Log the claim
            Log::info('Invitation claimed', [
                'user_id' => $user->id,
                'invited_by' => $invitation->inviter_id
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'Invitation accepted successfully'
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to claim invitation', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'token' => $validated['token']
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to accept invitation'
            ], 500);
        }
    }

    /**
     * Validate an invitation token (check if valid/not expired)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateToken(Request $request)
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'ok' => false,
                'valid' => false,
                'message' => 'Token is required'
            ], 400);
        }

        $invitation = Invitation::where('token', $token)->first();

        if (!$invitation) {
            return response()->json([
                'ok' => true,
                'valid' => false,
                'message' => 'Invitation not found'
            ]);
        }

        if ($invitation->isExpired()) {
            return response()->json([
                'ok' => true,
                'valid' => false,
                'message' => 'Invitation expired',
                'expired' => true
            ]);
        }

        if ($invitation->status !== 'pending') {
            return response()->json([
                'ok' => true,
                'valid' => false,
                'message' => 'Invitation already ' . $invitation->status
            ]);
        }

        return response()->json([
            'ok' => true,
            'valid' => true,
            'message' => 'Invitation is valid',
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at
        ]);
    }
}
