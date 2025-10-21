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
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('content')->limit(80)->wrap()->searchable(),
                \Filament\Tables\Columns\TextColumn::make('difficulty')->sortable()->badge(),
                \Filament\Tables\Columns\TextColumn::make('points')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('grade.name')->label('Grade')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('subject.name')->label('Subject')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('topic.name')->label('Topic')->sortable(),
                \Filament\Tables\Columns\IconColumn::make('is_approved')->boolean()->label('Approved'),
                \Filament\Tables\Columns\TextColumn::make('user.name')->label('Author')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('difficulty')->options(['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard']),
                \Filament\Tables\Filters\SelectFilter::make('grade')->relationship('grade','name'),
                \Filament\Tables\Filters\SelectFilter::make('subject')->relationship('subject','name'),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make(),
                \Filament\Tables\Actions\DeleteAction::make(),
                \Filament\Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->action(function ($record) {
                        $record->is_approved = true;
                        $record->approval_requested_at = null;
                        $record->save();
                    })
                    ->requiresConfirmation()
                    ->visible(fn($record) => !$record->is_approved),
                \Filament\Tables\Actions\Action::make('toggleApprove')
                    ->label('Toggle Approve')
                    ->action(function ($record) {
                        $record->is_approved = !$record->is_approved;
                        $record->save();
                    })
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                \Filament\Tables\Actions\DeleteBulkAction::make(),
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
