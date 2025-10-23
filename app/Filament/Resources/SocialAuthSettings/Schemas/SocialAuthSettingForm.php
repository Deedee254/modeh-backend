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
                    ->schema([
                        \Filament\Forms\Components\Select::make('provider')
                            ->label('OAuth Provider')
                            ->options(\App\Models\SocialAuthSetting::getProviderOptions())
                            ->required()
                            ->disabled(fn ($record) => $record !== null)
                            ->unique(ignoreRecord: true),
                            
                        \Filament\Forms\Components\TextInput::make('client_id')
                            ->label('Client ID')
                            ->required()
                            ->maxLength(255),
                            
                        \Filament\Forms\Components\TextInput::make('client_secret')
                            ->label('Client Secret')
                            ->required()
                            ->password()
                            ->maxLength(255),
                            
                        \Filament\Forms\Components\TextInput::make('redirect_url')
                            ->label('Redirect URL')
                            ->default(fn () => config('app.url') . '/api/auth/{provider}/callback')
                            ->helperText('The {provider} placeholder will be replaced with the actual provider name')
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
