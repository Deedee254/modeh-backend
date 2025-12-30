<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentSettingResource\Pages;
use App\Models\PaymentSetting;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class PaymentSettingResource extends Resource
{
    protected static ?string $model = PaymentSetting::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';
    protected static \UnitEnum|string|null $navigationGroup = 'Payments & Subscriptions';
    protected static ?int $navigationSort = 3;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            TextInput::make('gateway')
                ->disabled()
                ->dehydrated()
                ->helperText('Gateway is read-only. Configure via environment variables.'),
            TextInput::make('revenue_share')
                ->numeric()
                ->default(0)
                ->suffix('%')
                ->helperText('Platform revenue share percentage'),
            
            // M-Pesa Configuration (read-only from environment)
            TextInput::make('mpesa_environment')
                ->label('M-Pesa Environment')
                ->default(fn () => config('services.mpesa.environment') ?? 'Not configured')
                ->disabled()
                ->dehydrated(false)
                ->helperText('From MPESA_ENVIRONMENT in .env'),
            
            TextInput::make('mpesa_consumer_key')
                ->label('M-Pesa Consumer Key')
                ->default(fn () => config('services.mpesa.consumer_key') ? substr(config('services.mpesa.consumer_key'), 0, 10) . '...' : 'Not configured')
                ->disabled()
                ->dehydrated(false)
                ->helperText('From MPESA_CONSUMER_KEY in .env (masked for security)'),
            
            TextInput::make('mpesa_consumer_secret')
                ->label('M-Pesa Consumer Secret')
                ->default(fn () => config('services.mpesa.consumer_secret') ? '••••••••••' : 'Not configured')
                ->disabled()
                ->dehydrated(false)
                ->helperText('From MPESA_CONSUMER_SECRET in .env (hidden for security)'),
            
            TextInput::make('mpesa_shortcode')
                ->label('M-Pesa Shortcode')
                ->default(fn () => config('services.mpesa.shortcode') ?? 'Not configured')
                ->disabled()
                ->dehydrated(false)
                ->helperText('From MPESA_SHORTCODE in .env'),
            
            TextInput::make('mpesa_callback_url')
                ->label('M-Pesa Callback URL')
                ->default(fn () => config('services.mpesa.callback_url') ?? 'Not configured')
                ->disabled()
                ->dehydrated(false)
                ->helperText('From MPESA_CALLBACK_URL in .env'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('gateway'),
            TextColumn::make('revenue_share')->suffix('%'),
            TextColumn::make('updated_at')->date(),
        ])
        ->actions([
            ViewAction::make(),
            EditAction::make(),
            DeleteAction::make(),
        ])
        ->bulkActions([
            DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentSettings::route('/'),
            'create' => Pages\CreatePaymentSetting::route('/create'),
            'edit' => Pages\EditPaymentSetting::route('/{record}/edit'),
            'view' => Pages\ViewPaymentSetting::route('/{record}'),
        ];
    }
}
