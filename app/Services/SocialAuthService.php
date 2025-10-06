<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserOnboarding;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;

class SocialAuthService
{
    public function findOrCreateUser($socialUser, $provider)
    {
        return DB::transaction(function () use ($socialUser, $provider) {
            $user = User::where('social_id', $socialUser->getId())
                       ->where('social_provider', $provider)
                       ->first();

            if (!$user) {
                // Create new user if they don't exist
                $user = User::create([
                    'name' => $socialUser->getName(),
                    'email' => $socialUser->getEmail(),
                    'social_id' => $socialUser->getId(),
                    'social_provider' => $provider,
                    'social_avatar' => $socialUser->getAvatar(),
                    'social_token' => $socialUser->token,
                    'social_refresh_token' => $socialUser->refreshToken,
                    'social_expires_at' => isset($socialUser->expiresIn) ? now()->addSeconds($socialUser->expiresIn) : null,
                    'is_profile_completed' => false,
                ]);

                // Create onboarding record
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
                // Update existing user's social info
                $user->update([
                    'social_token' => $socialUser->token,
                    'social_refresh_token' => $socialUser->refreshToken,
                    'social_expires_at' => isset($socialUser->expiresIn) ? now()->addSeconds($socialUser->expiresIn) : null,
                ]);
            }

            return $user;
        });
    }
}