<?php

namespace App\Filament\Resources\SocialAuthSettings\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;

class SocialAuthSettingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Provider Configuration')
                    ->schema([
                        TextEntry::make('provider')
                            ->label('OAuth Provider')
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                            
                        TextEntry::make('client_id')
                            ->label('Client ID (from .env)')
                            ->state(fn ($record) => self::getEnvValue($record->provider, 'client_id') ?: '(not configured)'),
                            
                        TextEntry::make('client_secret')
                            ->label('Client Secret (from .env)')
                            ->state(fn ($record) => self::maskSecret(self::getEnvValue($record->provider, 'client_secret'))),
                            
                        TextEntry::make('redirect_url')
                            ->label('Redirect URL (from .env)')
                            ->state(fn ($record) => self::getEnvValue($record->provider, 'redirect_url') ?: '(not configured)')
                            ->copyable(),
                            
                        TextEntry::make('is_enabled')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Disabled')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            
                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime(),
                    ])
            ]);
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
