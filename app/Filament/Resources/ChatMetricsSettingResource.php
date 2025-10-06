<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChatMetricsSettingResource\Pages;
use App\Models\ChatMetricsSetting;
use Filament\Resources\Resource;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class ChatMetricsSettingResource extends Resource
{
    protected static ?string $model = ChatMetricsSetting::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-server';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('retention_days')->label('Retention (days)')->numeric()->minValue(1)->required()->default(30),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('retention_days'),
            TextColumn::make('updated_at')->date(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChatMetricsSettings::route('/'),
            'edit' => Pages\EditChatMetricsSetting::route('/{record}/edit'),
        ];
    }
}
