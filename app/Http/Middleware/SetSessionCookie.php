<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Middleware to set the session cookie name dynamically per request so that
 * different roles (quizee, quiz-master, admin) can maintain separate sessions
 * in the same browser.
 */
class SetSessionCookie
{
    /**
     * Map role -> cookie suffix
     * @var array
     */
    protected $map = [
        'quizee' => 'quizee',
        'quiz-master' => 'quizmaster',
        'admin' => 'admin',
    ];

    /**
     * Handle an incoming request.
     * We decide which session cookie name to use in this order:
     * 1. If a role is provided in the request body (login request), use that.
     * 2. If an existing cookie matches any role cookie names, use that.
     * 3. Fallback to default session cookie.
     */
    public function handle(Request $request, Closure $next)
    {
        $default = config('session.cookie');

        // 1) If role present in request (e.g. login), pick cookie name for that role
        $role = $request->input('role') ?? null;
        if ($role && isset($this->map[$role])) {
            $this->setCookieNameForRole($this->map[$role]);
            return $next($request);
        }

        // 2) Inspect incoming cookies for known role cookie names
        foreach ($this->map as $roleKey => $suffix) {
            $cookieName = $this->roleCookieName($suffix);
            if ($request->cookies->has($cookieName)) {
                $this->setCookieNameForRole($suffix);
                return $next($request);
            }
        }

        // 3) No role-specific cookie found - use default
        config(['session.cookie' => $default]);

        return $next($request);
    }

    protected function roleCookieName(string $suffix): string
    {
        // Normalize app name slug
        $app = Str::slug(config('app.name', 'laravel'));
        return "{$app}-session-{$suffix}";
    }

    protected function setCookieNameForRole(string $suffix)
    {
        config(['session.cookie' => $this->roleCookieName($suffix)]);
    }
}
