<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * SessionUserCacheService
 *
 * Manages user data caching in the HTTP session to reduce database queries on /api/me endpoints.
 * 
 * Instead of querying the database every request, we:
 * 1. Store serialized user data in session on login/register
 * 2. Return cached data if still valid (within TTL)
 * 3. Refresh from DB if cache expired or user was updated
 * 4. Invalidate cache when user profile changes
 *
 * This can reduce /api/me response times by 80-90% (100-150ms → 5-10ms for cache hits).
 * 
 * Session key structure:
 * {
 *   '_user_cache': {
 *     'data': { ... full user resource ... },
 *     'cached_at': '2026-02-02 10:30:00',
 *     'user_id': 123,
 *     'user_updated_at': '2026-02-02 10:25:00'
 *   }
 * }
 */
class SessionUserCacheService
{
    const SESSION_KEY = '_user_cache';
    
    /**
     * TTL in minutes for session cache before forcing a DB refresh
     * Set to 0 to disable session caching (useful for testing)
     * 
     * DEBUG: Set to 0 to bypass session cache and force fresh DB queries
     * This helps isolate cache-related bugs where wrong user data is returned
     */
    const CACHE_TTL_MINUTES = 15;

    /**
     * Store full user data in session after login/registration
     * 
     * @param User $user
     * @param Request $request
     * @return array Cached user data (same format as /api/me response)
     */
    public static function cacheUserInSession(User $user, Request $request): array
    {
        if (!$request->hasSession() || self::CACHE_TTL_MINUTES === 0) {
            return [];
        }

        try {
            // Load relationships to include in cache
            $user->loadMissing(['affiliate', 'institutions', 'onboarding']);
            
            // Get role-specific profile
            if ($user->role === 'quiz-master') {
                $user->loadMissing('quizMasterProfile.grade', 'quizMasterProfile.level', 'quizMasterProfile.institution');
            } elseif ($user->role === 'quizee') {
                $user->loadMissing('quizeeProfile.grade', 'quizeeProfile.level', 'quizeeProfile.institution');
            }

            // Transform to UserResource format (same as /api/me response)
            $userData = UserResource::make($user)->toArray($request);

            // Store in session with metadata
            $cacheData = [
                'data' => $userData,
                'cached_at' => now()->toDateTimeString(),
                'user_id' => $user->id,
                'user_updated_at' => $user->updated_at->toDateTimeString(),
            ];

            $request->session()->put(self::SESSION_KEY, $cacheData);

            Log::debug('[SessionCache] User cached in session', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ttl_minutes' => self::CACHE_TTL_MINUTES,
            ]);

            return $userData;
        } catch (\Exception $e) {
            Log::warning('[SessionCache] Failed to cache user in session', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? 'unknown',
            ]);
            // Fail gracefully - return empty cache, caller will fetch fresh data
            return [];
        }
    }

    /**
     * Retrieve user data from session cache if valid and fresh
     * 
     * Returns cached data if:
     * - Cache exists in session
     * - Cache is within TTL
     * - User hasn't been modified since cache time
     * 
     * @param User $user
     * @param Request $request
     * @return array|null Cached user data, or null if cache invalid/expired
     */
    public static function getUserFromSessionCache(User $user, Request $request): ?array
    {
        if (!$request->hasSession() || self::CACHE_TTL_MINUTES === 0) {
            return null;
        }

        try {
            $cache = $request->session()->get(self::SESSION_KEY);

            // Cache missing or wrong user
            if (!$cache || !is_array($cache) || ($cache['user_id'] ?? null) !== $user->id) {
                return null;
            }

            // Validate cache structure
            if (!isset($cache['data'], $cache['cached_at'], $cache['user_updated_at'])) {
                return null;
            }

            // Check TTL: is cache still within valid window?
            $cacheAge = Carbon::parse($cache['cached_at'])->diffInMinutes(now());
            if ($cacheAge > self::CACHE_TTL_MINUTES) {
                Log::debug('[SessionCache] Cache expired', [
                    'user_id' => $user->id,
                    'cache_age_minutes' => $cacheAge,
                ]);
                return null;
            }

            // Check if user was modified after cache was created
            // If so, cache is stale and we need fresh data
            $userModifiedAt = $user->updated_at ? $user->updated_at->toDateTimeString() : null;
            $cacheCreatedAt = $cache['user_updated_at'];
            
            if ($userModifiedAt && $userModifiedAt !== $cacheCreatedAt) {
                Log::debug('[SessionCache] User was modified after cache', [
                    'user_id' => $user->id,
                    'cache_user_updated_at' => $cacheCreatedAt,
                    'current_user_updated_at' => $userModifiedAt,
                ]);
                return null;
            }

            Log::debug('[SessionCache] Cache hit', [
                'user_id' => $user->id,
                'cache_age_minutes' => $cacheAge,
            ]);

            return $cache['data'];
        } catch (\Exception $e) {
            Log::warning('[SessionCache] Error retrieving from session cache', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? 'unknown',
            ]);
            return null;
        }
    }

    /**
     * Invalidate user cache in session
     * Call this whenever user profile is updated
     * 
     * @param int|User $userOrId
     * @param Request $request
     * @return void
     */
    public static function invalidateSessionCache($userOrId, Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        try {
            $userId = is_object($userOrId) ? $userOrId->id : $userOrId;
            $cache = $request->session()->get(self::SESSION_KEY);

            // Only invalidate if it's the same user's cache
            if ($cache && ($cache['user_id'] ?? null) === $userId) {
                $request->session()->forget(self::SESSION_KEY);
                
                Log::debug('[SessionCache] Cache invalidated', ['user_id' => $userId]);
            }
        } catch (\Exception $e) {
            Log::warning('[SessionCache] Error invalidating session cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear session cache on logout
     * 
     * @param Request $request
     * @return void
     */
    public static function clearOnLogout(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
            Log::debug('[SessionCache] Cache cleared on logout');
        }
    }
}
