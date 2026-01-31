<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

/**
 * Application-level CSRF verifier.
 *
 * We extend the framework verifier so we can add API routes that should be
 * exempt from CSRF protection (public guest endpoints). This is safer than
 * disabling the stateful API behavior globally.
 */
class VerifyCsrfToken extends BaseVerifier
{
    /**
     * The URIs that should be excluded from CSRF verification.
     * These routes don't require CSRF tokens because:
     * - Guest endpoints: publicly accessible
     * - Login/auth: Nuxt-Auth doesn't send CSRF tokens (uses Bearer tokens instead)
     * - Onboarding: authenticated users only, no form submissions
     * - Broadcasting: uses Bearer token auth
     *
     * @var array<int, string>
     */
    protected $except = [
        // Public read-only endpoints (no state changes)
        'api/quizzes',
        'api/quizzes/*',
        'api/questions',
        'api/questions/*',
        'api/grades',
        
        // Guest quiz endpoints (public, no authentication)
        'api/guest/quizzes/*/submit',
        'api/guest/quizzes/*/mark',
        
        // Authentication endpoints (Nuxt-Auth doesn't send CSRF)
        'api/login',
        'api/auth/*',
        'api/auth/social-sync',
        
        // User onboarding (authenticated, no CSRF needed)
        'api/onboarding/*',
        
        // Real-time broadcasting (Bearer token auth)
        'api/broadcasting/auth',
        
        // Quiz attempts (may be marked without CSRF)
        'api/quiz-attempts/*/mark',
        
        // Admin routes (separate from API)
        'admin/login',
    ];
}
