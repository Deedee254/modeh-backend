## 2026-03-20 - [Bypassing Heartbeat Secret Verification & Insecure CSRF Cookie due to Config Caching]
**Vulnerability:** When Laravel configuration caching (`php artisan config:cache`) is enabled in production, all `env()` helper calls outside of configuration files return `null`. This resulted in two security flaws:
1. `$secret = env('ECHO_HEARTBEAT_SECRET')` evaluated to `null`, completely bypassing the heartbeat authorization check and leaving the POST endpoint unprotected.
2. `(bool) env('SESSION_SECURE_COOKIE', false)` evaluated to `false`, leaving session and CSRF (`XSRF-TOKEN`) cookies without the `Secure` flag on HTTPS connections.

**Learning:** Any custom security verification relying on `env()` inside controllers, middleware, services, or routes will silently fail or bypass when configuration caching is active in production.
**Prevention:** Always register environment-dependent variables within Laravel config files (`config/*.php`) and retrieve them using `config('...')`. Avoid using direct `env()` helper calls anywhere else in the application.
