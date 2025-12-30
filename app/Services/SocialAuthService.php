<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SocialAuthService
{
    public function findOrCreateUser($socialUser, $provider)
    {
        try {
            return DB::transaction(function () use ($socialUser, $provider) {
                $user = User::where('social_id', $socialUser->getId())
                    ->where('social_provider', $provider)
                    ->first();

                // If we didn't find a social-linked user, try to link by email
                // For existing users with verified emails, link the social account
                // For existing users with unverified emails, we still link to prevent duplicate accounts
                // The social provider's email verification serves as verification
                if (!$user) {
                    $email = $socialUser->getEmail();
                    if ($email) {
                        // First try to find user with verified email
                        $user = User::whereEmail($email)->whereNotNull('email_verified_at')->first();
                        
                        // If no verified user found, check for unverified user
                        // Link to prevent duplicate accounts - social provider email is considered verified
                        if (!$user) {
                            $existingUser = User::whereEmail($email)->first();
                            if ($existingUser) {
                                // Link social account to existing user and mark email as verified
                                // since the social provider has verified the email
                                $existingUser->update([
                                    'email_verified_at' => $existingUser->email_verified_at ?? now(),
                                ]);
                                $user = $existingUser;
                            }
                        }
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
                        'social_token' => Crypt::encryptString($socialUser->token),
                        'social_refresh_token' => Crypt::encryptString($socialUser->refreshToken),
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
                        'social_token' => Crypt::encryptString($socialUser->token),
                        'social_refresh_token' => Crypt::encryptString($socialUser->refreshToken),
                        'social_expires_at' => isset($socialUser->expiresIn) ? now()->addSeconds($socialUser->expiresIn) : null,
                    ]);
                }

                return $user;
            });
        } catch (Throwable $e) {
            // Log the exception
            report($e);

            // Return null or re-throw a custom exception
            return null;
        }
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

    /**
     * Disconnect a user's social account.
     */
    public function disconnectSocialAccount(User $user)
    {
        $user->update([
            'social_id' => null,
            'social_provider' => null,
            'social_avatar' => null,
            'social_token' => null,
            'social_refresh_token' => null,
            'social_expires_at' => null,
        ]);
    }
}