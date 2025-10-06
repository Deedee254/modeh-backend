<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAuthSetting extends Model
{
    protected $fillable = [
        'provider',
        'client_id',
        'client_secret',
        'redirect_url',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public static function getProviderOptions(): array
    {
        return [
            'google' => 'Google',
            'facebook' => 'Facebook',
            'github' => 'GitHub',
            'twitter' => 'Twitter',
        ];
    }

    protected static function booted()
    {
        static::saved(function ($setting) {
            // Clear the services config cache when settings are updated
            \Illuminate\Support\Facades\Artisan::call('config:clear');
        });
    }
}
