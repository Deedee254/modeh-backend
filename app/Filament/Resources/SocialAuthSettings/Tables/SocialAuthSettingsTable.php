<?php

namespace App\Filament\Resources\SocialAuthSettings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

class SocialAuthSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('provider')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    
                \Filament\Tables\Columns\TextColumn::make('client_id')
                    ->label('Client ID (from .env)')
                    ->formatStateUsing(fn ($record) => self::getEnvValue($record->provider, 'client_id') ?: '(not configured)')
                    ->toggleable(),
                    
                \Filament\Tables\Columns\TextColumn::make('redirect_url')
                    ->label('Redirect URL (from .env)')
                    ->formatStateUsing(fn ($record) => self::getEnvValue($record->provider, 'redirect_url') ?: '(not configured)')
                    ->toggleable()
                    ->wrap(),
                    
                \Filament\Tables\Columns\IconColumn::make('is_enabled')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),
                    
                \Filament\Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
}
