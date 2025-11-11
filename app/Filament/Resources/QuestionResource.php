<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuestionResource\Pages;
use App\Models\Question;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class QuestionResource extends Resource
{
    protected static ?string $model = Question::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-question-mark-circle';
    protected static \UnitEnum|string|null $navigationGroup = 'Academic Content';
    protected static ?int $navigationSort = 2;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    Forms\Components\Textarea::make('body')->label('Question')->required()->rows(4),
                    Forms\Components\Select::make('type')
                        ->options(['mcq' => 'Multiple Choice', 'multi' => 'Multiple Answer', 'short' => 'Short Answer', 'numeric' => 'Numeric', 'fill_blank' => 'Fill in Blanks', 'math' => 'Math', 'code' => 'Code', 'essay' => 'Essay'])
                        ->required(),
                    Forms\Components\Select::make('difficulty')
                        ->options([1 => 'Very Easy', 2 => 'Easy', 3 => 'Medium', 4 => 'Hard', 5 => 'Very Hard'])
                        ->required()
                        ->native(false),
                    Forms\Components\TextInput::make('marks')
                        ->numeric()
                        ->default(1)
                        ->required(),
                    Forms\Components\Select::make('grade_id')
                        ->relationship('grade', 'name')
                        ->required()
                        ->native(false),
                    Forms\Components\Select::make('subject_id')
                        ->relationship('subject', 'name')
                        ->required()
                        ->native(false),
                    Forms\Components\Select::make('topic_id')
                        ->relationship('topic', 'name')
                        ->native(false),
                    Forms\Components\Select::make('level_id')
                        ->relationship('level', 'name')
                        ->native(false),
                    Forms\Components\Repeater::make('options')
                        ->schema([
                            Forms\Components\TextInput::make('text')->label('Option Text')->required(),
                            Forms\Components\Hidden::make('label'),
                        ])
                        ->createItemButtonLabel('Add option')
                        ->minItems(2)
                        ->maxItems(6)
                        ->visible(fn($get) => in_array($get('type'), ['mcq', 'multi'])),
                    Forms\Components\Textarea::make('answers')
                        ->label('Correct Answers (JSON array)')
                        ->rows(3)
                        ->helperText('e.g., ["Option A", "Option B"] or [0, 2] for indices')
                        ->json(),
                    Forms\Components\Textarea::make('explanation')
                        ->label('Explanation')
                        ->rows(3),
                    Forms\Components\Toggle::make('is_approved')
                        ->label('Approved'),
                    Forms\Components\Toggle::make('is_banked')
                        ->label('Banked Question'),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('body')->label('Question')->limit(80)->wrap()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('type')->sortable()->badge(),
                \Filament\Tables\Columns\TextColumn::make('difficulty')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(fn($state) => match((int)$state) {
                        1 => 'Very Easy',
                        2 => 'Easy',
                        3 => 'Medium',
                        4 => 'Hard',
                        5 => 'Very Hard',
                        default => $state
                    })
                    ->color(fn($state) => match((int)$state) {
                        1 => 'success',
                        2 => 'info',
                        3 => 'warning',
                        4 => 'danger',
                        5 => 'error',
                        default => 'gray'
                    }),
                \Filament\Tables\Columns\TextColumn::make('marks')->label('Points')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('grade.name')->label('Grade')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('subject.name')->label('Subject')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('topic.name')->label('Topic')->sortable(),
                \Filament\Tables\Columns\IconColumn::make('is_approved')->boolean()->label('Approved'),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('difficulty')
                    ->options([1 => 'Very Easy', 2 => 'Easy', 3 => 'Medium', 4 => 'Hard', 5 => 'Very Hard']),
                \Filament\Tables\Filters\SelectFilter::make('grade')->relationship('grade','name'),
                \Filament\Tables\Filters\SelectFilter::make('subject')->relationship('subject','name'),
            ])
            ->actions([
                \Filament\Actions\Action::make('approve')
                    ->label('Approve')
                    ->action(function ($record) {
                        $record->is_approved = true;
                        $record->approval_requested_at = null;
                        $record->save();
                    })
                    ->requiresConfirmation()
                    ->visible(fn($record) => !$record->is_approved),
                \Filament\Actions\Action::make('toggleApprove')
                    ->label('Toggle Approve')
                    ->action(function ($record) {
                        $record->is_approved = !$record->is_approved;
                        $record->save();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                    \Filament\Actions\EditAction::make(),
                    \Filament\Actions\DeleteAction::make(),
                ])
                ->bulkActions([
                    \Filament\Actions\DeleteBulkAction::make(),
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
