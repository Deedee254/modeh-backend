<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Exclude Livewire endpoints which are internal AJAX POSTs and manage their own tokens
        'livewire/*',
        '_livewire/*',
        // Allow Filament admin panel requests to handle CSRF using its own middleware
        'admin',
        'admin/*',
    ];
}