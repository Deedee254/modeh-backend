<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuizResource\Pages;
use App\Models\Quiz;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
// Note: don't import Tables\Actions to avoid alias conflicts; use fully-qualified names below

class QuizResource extends Resource
{
    protected static ?string $model = Quiz::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static \UnitEnum|string|null $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 4;

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            TextColumn::make('title')->label('Title'),
            \Filament\Forms\Components\TextInput::make('one_off_price')
                ->numeric()
                ->label('One-off Price')
                ->min(0)
                ->step(0.01),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('title')->searchable()->label('Quiz'),
                TextColumn::make('topic.name')->label('Topic'),
                TextColumn::make('one_off_price')
                    ->label('One-off Price')
                    ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2) : '-')
                    ->sortable(),
                IconColumn::make('is_approved')->boolean()->label('Approved'),
            ])
            ->actions([
                \Filament\Tables\Actions\ViewAction::make(),
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizzes::route('/'),
            // Create/Edit pages are not present in the Pages folder; only register pages that exist to allow package discovery.
            'view' => Pages\ViewQuiz::route('/{record}'),
        ];
    }
}
