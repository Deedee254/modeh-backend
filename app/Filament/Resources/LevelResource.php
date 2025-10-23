<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LevelResource\Pages;
use App\Models\Level;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;

class LevelResource extends Resource
{
    protected static ?string $model = Level::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-book-open';
    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return 'Content Management';
    }

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('slug')->maxLength(255),
                    Forms\Components\TextInput::make('order')->numeric()->default(0),
                    Forms\Components\Textarea::make('description')->maxLength(65535),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->sortable(),
                Tables\Columns\TextColumn::make('order')->sortable(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                    \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLevels::route('/'),
            'create' => Pages\CreateLevel::route('/create'),
            'edit' => Pages\EditLevel::route('/{record}/edit'),
            'view' => Pages\ViewLevel::route('/{record}'),
        ];
    }
}
