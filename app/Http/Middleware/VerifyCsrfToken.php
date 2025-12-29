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
     * Add API guest endpoints here so they remain public.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Guest quiz submission and per-question marking (public endpoints)
        'api/quizzes/*/submit',
        'api/quizzes/*/mark',
        // You can add other public API endpoints here if needed
    ];
}
