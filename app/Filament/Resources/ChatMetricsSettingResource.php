<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMetricsSettingResource\Pages;
use App\Models\ChatMetricsSetting;
use Filament\Resources\Resource;
// avoid importing Filament\Forms\Form to prevent signature mismatches
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class ChatMetricsSettingResource extends Resource
{
    protected static ?string $model = ChatMetricsSetting::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-server';
    protected static \UnitEnum|string|null $navigationGroup = 'User Engagement';
    protected static ?int $navigationSort = 2;

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            TextInput::make('retention_days')->label('Retention (days)')->numeric()->minValue(1)->required()->default(30),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('retention_days'),
            TextColumn::make('updated_at')->date(),
        ])
        ->actions([
              \Filament\Actions\ViewAction::make(),
              \Filament\Actions\EditAction::make(),
              \Filament\Actions\DeleteAction::make(),
        ])
        ->bulkActions([
              \Filament\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatMetricsSettings::route('/'),
            'edit' => Pages\EditChatMetricsSetting::route('/{record}/edit'),
            'view' => Pages\ViewChatMetricsSetting::route('/{record}'),
        ];
    }
}
