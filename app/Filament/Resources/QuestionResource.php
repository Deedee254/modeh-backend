<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Models\Question;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;
use BackedEnum;
use Filament\Tables;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static UnitEnum|string|null $navigationGroup = 'Content';
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Card::make()
                    ->schema([
                        Forms\Components\Textarea::make('content')->required()->rows(4),
                        Forms\Components\Select::make('difficulty')
                            ->options(['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'])
                            ->required(),
                        Forms\Components\TextInput::make('points')->numeric()->default(1),
                        Forms\Components\Select::make('grade_id')->relationship('grade', 'name')->required(),
                        Forms\Components\Select::make('subject_id')->relationship('subject', 'name')->required(),
                        Forms\Components\Select::make('topic_id')->relationship('topic', 'name')->visible(fn($get) => filled($get('subject_id'))),
                        Forms\Components\Repeater::make('options')
                            ->schema([
                                Forms\Components\TextInput::make('content')->required(),
                                Forms\Components\Toggle::make('is_correct')->label('Correct?')
                            ])
                            ->createItemButtonLabel('Add option')
                            ->minItems(2)
                            ->maxItems(6),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('content')->limit(80)->wrap()->searchable(),
                Tables\Columns\TextColumn::make('difficulty')->sortable()->badge(),
                Tables\Columns\TextColumn::make('points')->sortable(),
                Tables\Columns\TextColumn::make('grade.name')->label('Grade')->sortable(),
                Tables\Columns\TextColumn::make('subject.name')->label('Subject')->sortable(),
                Tables\Columns\TextColumn::make('topic.name')->label('Topic')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('difficulty')->options(['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard']),
                Tables\Filters\SelectFilter::make('grade')->relationship('grade','name'),
                Tables\Filters\SelectFilter::make('subject')->relationship('subject','name'),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuestions::route('/'),
            'create' => Pages\CreateQuestion::route('/create'),
            'edit' => Pages\EditQuestion::route('/{record}/edit'),
        ];
    }
}
