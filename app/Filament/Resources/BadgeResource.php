<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BadgeResource\Pages;
use App\Models\Badge;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class BadgeResource extends Resource
{
    protected static ?string $model = Badge::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Textarea::make('description'),
            TextInput::make('icon'),
            Select::make('type')
                ->options([
                    'difficulty' => 'Difficulty',
                    'mode' => 'Mode',
                    'meta' => 'Meta',
                ])
                ->required(),
            Textarea::make('criteria'),
            TextInput::make('points_reward')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('type'),
            TextColumn::make('points_reward'),
            TextColumn::make('created_at')->date(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }
}