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
                            ->disabled(fn ($record) => $record !== null),
                            
                        \Filament\Forms\Components\TextInput::make('client_id')
                            ->label('Client ID (from .env: GOOGLE_CLIENT_ID)')
                            ->default(fn () => config('services.google.client_id'))
                            ->disabled()
                            ->dehydrated(false),
                            
                        \Filament\Forms\Components\TextInput::make('client_secret')
                            ->label('Client Secret (from .env: GOOGLE_CLIENT_SECRET)')
                            ->default(fn () => config('services.google.client_secret'))
                            ->password()
                            ->revealable()
                            ->disabled()
                            ->dehydrated(false),
                            
                        \Filament\Forms\Components\TextInput::make('redirect_url')
                            ->label('Redirect URL')
                            ->default(fn ($record) => $record?->redirect_url ?? config('app.url') . '/auth/google/callback')
                            ->helperText('Configured in database per provider')
                            ->required()
                            ->maxLength(255),
                            
                        \Filament\Forms\Components\Toggle::make('is_enabled')
                            ->label('Enable Provider')
                            ->default(false)
                            ->helperText('Enable or disable this social login provider')
                            ->required(),
                    ])
            ]);
    }
}

