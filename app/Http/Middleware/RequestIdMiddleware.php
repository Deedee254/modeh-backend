<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

/**
 * Adds a X-Request-Id header and a logging context key for request correlation.
 */
class RequestIdMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Generate a stable UUID per request for correlation
        $requestId = (string) Str::uuid();

        // Attach to request headers so downstream code (and frontend) can see it
        $request->headers->set('X-Request-Id', $requestId);

        // Add to the global log context for this request lifecycle
        try {
            Log::withContext(['request_id' => $requestId]);
        } catch (\Throwable $e) {
            // Non-fatal if logging context cannot be set
        }

        $response = $next($request);

        // Ensure response carries the same header so clients can correlate
        if (method_exists($response, 'headers')) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}
