<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class SocialAuthSettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        try {
            // Only try to load settings if the database table exists
            if (!\Schema::hasTable('social_auth_settings')) {
                return;
            }

            $settings = \App\Models\SocialAuthSetting::where('is_enabled', true)->get();

            foreach ($settings as $setting) {
                // Replace {provider} placeholder in redirect URL
                $redirectUrl = str_replace('{provider}', $setting->provider, $setting->redirect_url);

                // Add the configuration
                config([
                    "services.{$setting->provider}" => [
                        'client_id' => $setting->client_id,
                        'client_secret' => $setting->client_secret,
                        'redirect' => $redirectUrl,
                    ]
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't crash the application (guards artisan and other CLI commands)
            \Log::error('Failed to load social auth settings: ' . $e->getMessage());
            return;
        }
    }
}
