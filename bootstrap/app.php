<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
        ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'api/quizzes',
            'api/quizzes/*',
            'api/quizzes/*/submit',
            'api/quizzes/*/mark',
            'api/questions',
            'api/questions/*',
            'api/guest/quizzes/*/submit',
            'api/guest/quizzes/*/mark',
            'api/quiz-attempts/*/mark',
            'api/register/*',
            'api/auth/*',
            'api/login',  // Nuxt-Auth credentials provider doesn't send CSRF tokens
            'api/onboarding/*',  // Onboarding steps don't require CSRF (authenticated users only, no credentials sent)
            'api/broadcasting/auth',  // Uses Bearer token auth, doesn't send CSRF
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
