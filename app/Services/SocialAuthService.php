<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialAuthService
{
    public function findOrCreateUser($socialUser, $provider)
    {
        return DB::transaction(function () use ($socialUser, $provider) {
            $user = User::where('social_id', $socialUser->getId())
                       ->where('social_provider', $provider)
                       ->first();

            // If we didn't find a social-linked user, try to find an existing
            // account by email and attach the social fields. This prevents
            // creating duplicate accounts when a user previously signed up
            // with the same email.
            if (!$user) {
                $email = $socialUser->getEmail();
                if ($email) {
                    $user = User::where('email', $email)->first();
                }
            }

            if (!$user) {
                // Create new user if they don't exist. Provide a random
                // password as a fallback for non-nullable password column.
                $user = User::create([
                    'name' => $socialUser->getName(),
                    'email' => $socialUser->getEmail(),
                    'password' => Str::random(40),
                    'social_id' => $socialUser->getId(),
                    'social_provider' => $provider,
                    'social_avatar' => $socialUser->getAvatar(),
                    'social_token' => $socialUser->token,
                    'social_refresh_token' => $socialUser->refreshToken,
                    'social_expires_at' => isset($socialUser->expiresIn) ? now()->addSeconds($socialUser->expiresIn) : null,
                    'is_profile_completed' => false,
                ]);

                // Create onboarding record for new users only
                UserOnboarding::create([
                    'user_id' => $user->id,
                    'profile_completed' => false,
                    'institution_added' => false,
                    'role_selected' => false,
                    'subject_selected' => false,
                    'grade_selected' => false,
                    'completed_steps' => ['social_auth'],
                    'last_step_completed_at' => now()
                ]);
            } else {
                // Update or attach social fields to the existing user (found
                // by social id or by email). We set social_id/provider if
                // missing and always refresh tokens/expires info.
                $user->update([
                    'social_id' => $socialUser->getId(),
                    'social_provider' => $provider,
                    'social_avatar' => $socialUser->getAvatar(),
                    'social_token' => $socialUser->token,
                    'social_refresh_token' => $socialUser->refreshToken,
                    'social_expires_at' => isset($socialUser->expiresIn) ? now()->addSeconds($socialUser->expiresIn) : null,
                ]);
            }

            return $user;
        });
    }

    /**
     * Revoke all personal access tokens for a user.
     * Called before issuing a new token to ensure only one active token exists.
     */
    public function revokeAllTokens($user)
    {
        if (!$user || !$user->id) {
            return;
        }
        
        // Delete all personal access tokens for this user
        $user->tokens()->delete();
    }

}