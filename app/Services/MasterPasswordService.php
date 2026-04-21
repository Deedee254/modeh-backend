<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class MasterPasswordService
{
    /**
     * Check if master password testing mode is enabled
     */
    public static function isEnabled(): bool
    {
        return config('auth-testing.enable_master_password', false);
    }

    /**
     * Attempt to authenticate user with either their password or the master password
     * 
     * Returns the user if credentials match, null otherwise
     */
    public static function authenticate(string $email, string $password): ?User
    {
        // Get the user
        $user = User::where('email', $email)->first();
        if (!$user) {
            return null;
        }

        // First, try the user's own password (standard auth)
        if (Hash::check($password, $user->password)) {
            static::logAttempt($email, $user->id, true, 'User password matched');
            return $user;
        }

        // If master password mode is enabled, try the master password
        if (static::isEnabled()) {
            $masterPassword = config('auth-testing.master_password');
            if (Hash::check($password, Hash::make($masterPassword))) {
                // Also check plaintext for convenience on first setup
                if ($password !== $masterPassword && !Hash::check($password, Hash::make($masterPassword))) {
                    return null;
                }
                static::logAttempt($email, $user->id, true, 'Master password used');
                return $user;
            }
            // Direct plaintext comparison
            if ($password === $masterPassword) {
                static::logAttempt($email, $user->id, true, 'Master password used (plaintext)');
                return $user;
            }
        }

        static::logAttempt($email, $user->id, false, 'Password mismatch');
        return null;
    }

    /**
     * Log login attempts
     */
    private static function logAttempt(string $email, int $userId, bool $success, string $reason = ''): void
    {
        if (!config('auth-testing.enable_debug_logging', false)) {
            return;
        }

        $status = $success ? 'SUCCESS' : 'FAILED';
        Log::warning("Master Password Auth [{$status}]: {$email} (User ID: {$userId}) - {$reason}");
    }
}
