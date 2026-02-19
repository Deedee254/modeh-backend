<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PricingSettingResource\Pages;
use App\Models\PricingSetting;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteBulkAction;

class PricingSettingResource extends Resource
{
    protected static ?string $model = PricingSetting::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-tag';
    protected static UnitEnum|string|null $navigationGroup = 'Payments & Subscriptions';
    protected static ?int $navigationSort = 2;
    protected static ?string $label = 'Default Pricing';
    protected static ?string $pluralLabel = 'Default Pricing';

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Section::make('Global Default Prices')
                ->description('Set default one-off prices used when a quiz or battle has no price configured.')
                ->schema([
                    TextInput::make('default_quiz_one_off_price')
                        ->label('Default Quiz One-Off Price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->placeholder('e.g., 500')
                        ->helperText('Used when a quiz has no price set. Leave blank for free.'),
                    
                    TextInput::make('default_battle_one_off_price')
                        ->label('Default Battle One-Off Price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.01)
                        ->placeholder('e.g., 500')
                        ->helperText('Used when a battle has no price set. Leave blank for free.'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('default_quiz_one_off_price')
                    ->label('Default Quiz Price')
                    ->formatStateUsing(fn($state) => $state ? number_format($state, 2) : '—')
                    ->sortable(),
                TextColumn::make('default_battle_one_off_price')
                    ->label('Default Battle Price')
                    ->formatStateUsing(fn($state) => $state ? number_format($state, 2) : '—')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPricingSettings::route('/'),
            'create' => Pages\CreatePricingSetting::route('/create'),
            'edit' => Pages\EditPricingSetting::route('/{record}/edit'),
            'view' => Pages\ViewPricingSetting::route('/{record}'),
        ];
    }
}
