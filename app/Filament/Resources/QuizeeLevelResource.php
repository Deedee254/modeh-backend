<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuizeeLevelResource\Pages;
use App\Models\QuizeeLevel;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Card;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ColorColumn;
use Illuminate\Database\Eloquent\Builder;

class QuizeeLevelResource extends Resource
{
    protected static ?string $model = QuizeeLevel::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';
    
    protected static \UnitEnum|string|null $navigationGroup = 'Gamification';

    protected static ?string $navigationLabel = 'Quizee Levels';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Card::make()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('min_points')
                        ->required()
                        ->numeric()
                        ->minValue(0),
                    TextInput::make('max_points')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Leave empty for highest level'),
                    TextInput::make('icon')
                        ->maxLength(255)
                        ->helperText('Icon or emoji representing this level'),
                    Textarea::make('description')
                        ->maxLength(65535),
                    ColorPicker::make('color_scheme')
                        ->helperText('Color theme for this level'),
                    TextInput::make('order')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Order in which levels appear'),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('min_points')
                    ->sortable(),
                TextColumn::make('max_points')
                    ->sortable(),
                TextColumn::make('icon'),
                ColorColumn::make('color_scheme'),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->defaultSort('order')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuizeeLevels::route('/'),
            'create' => Pages\CreateQuizeeLevel::route('/create'),
            'edit' => Pages\EditQuizeeLevel::route('/{record}/edit'),
        ];
    }    
}