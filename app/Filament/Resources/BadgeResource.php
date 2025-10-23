<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BadgeResource\Pages;
use App\Models\Badge;
// Note: avoid importing Filament\Forms\Form directly to prevent signature conflicts with installed Filament version
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
    protected static ?int $navigationSort = 4;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';

    public static function getNavigationGroup(): ?string
    {
        return 'Content Management';
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
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
            'index' => Pages\ListBadges::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
            'view' => Pages\ViewBadge::route('/{record}'),
        ];
    }
}