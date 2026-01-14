<?php

namespace App\Filament\Resources\SocialAuthSettings\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

class SocialAuthSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Provider Configuration')
                    ->description('Settings are loaded from environment variables (.env file). Edit the .env file and run `php artisan config:clear` to update.')
                    ->schema([
                        \Filament\Forms\Components\Select::make('provider')
                            ->label('OAuth Provider')
                            ->options(\App\Models\SocialAuthSetting::getProviderOptions())
                            ->required()
                            ->disabled(fn ($record) => $record !== null)
                            ->dehydrated(fn ($record) => $record === null),
                            
                        \Filament\Forms\Components\TextInput::make('client_id')
                            ->label(fn ($get) => self::getEnvKeyLabel($get('provider'), 'CLIENT_ID'))
                            ->default(fn ($get) => self::getEnvValue($get('provider'), 'client_id'))
                            ->readOnly()
                            ->dehydrated(false),
                            
                        \Filament\Forms\Components\TextInput::make('client_secret')
                            ->label(fn ($get) => self::getEnvKeyLabel($get('provider'), 'CLIENT_SECRET'))
                            ->default(fn ($get) => self::maskSecret(self::getEnvValue($get('provider'), 'client_secret')))
                            ->readOnly()
                            ->dehydrated(false),
                            
                        \Filament\Forms\Components\TextInput::make('redirect_url')
                            ->label(fn ($get) => self::getEnvKeyLabel($get('provider'), 'OAUTH_REDIRECT_URI'))
                            ->default(fn ($get) => self::getEnvValue($get('provider'), 'redirect_url'))
                            ->readOnly()
                            ->dehydrated(false)
                            ->helperText('Loaded from .env file'),
                        
                        \Filament\Forms\Components\Toggle::make('is_enabled')
                            ->label('Enable Provider')
                            ->default(false)
                            ->helperText('Enable or disable this social login provider')
                            ->required(),
                    ])
            ]);
    }

    private static function getEnvKeyLabel($provider, $suffix): string
    {
        if (!$provider) return "Environment Variable ($suffix)";
        $providerUpper = strtoupper($provider);
        return "from .env: {$providerUpper}_{$suffix}";
    }

    private static function getEnvValue($provider, $type): ?string
    {
        if (!$provider) return null;
        
        $providerUpper = strtoupper($provider);
        
        return match ($type) {
            'client_id' => config("services.{$provider}.client_id") ?? env("{$providerUpper}_CLIENT_ID"),
            'client_secret' => config("services.{$provider}.client_secret") ?? env("{$providerUpper}_CLIENT_SECRET"),
            'redirect_url' => env("{$providerUpper}_OAUTH_REDIRECT_URI") ?? config('app.url') . "/auth/{$provider}/callback",
            default => null,
        };
    }

    private static function maskSecret($secret): string
    {
        if (!$secret) return '(not configured)';
        if (strlen($secret) <= 4) return str_repeat('•', strlen($secret));
        return substr($secret, 0, 2) . str_repeat('•', strlen($secret) - 4) . substr($secret, -2);
    }
}