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
