<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Convert auth_token cookie to Bearer token in the Authorization header.
 * This allows API requests to authenticate via HttpOnly cookies set by backend
 * (e.g., from OAuth callback) without requiring clients to manually send the
 * Authorization header. The header is only set if not already present.
 * Supports both session-based (Sanctum) and token-based (Bearer) auth flows.
 */
class CookieToBearer
{
    public function handle(Request $request, Closure $next)
    {
        // If Authorization header is already set, do not override.
        if ($request->hasHeader('Authorization')) {
            return $next($request);
        }

        // Check for auth_token cookie.
        $token = $request->cookie('auth_token');
        if ($token) {
            // Set the Authorization header with Bearer scheme.
            $request->headers->set('Authorization', "Bearer {$token}");
        }

        return $next($request);
    }
}
