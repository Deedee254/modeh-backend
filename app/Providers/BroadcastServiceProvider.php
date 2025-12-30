<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // Don't register default broadcasting routes - we handle it in api.php
        // Broadcast::routes();

        // Load the channel authorization routes
        require base_path('routes/channels.php');
    }
}
