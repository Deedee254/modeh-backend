<?php

namespace App\Filament\Resources\TournamentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DetachAction;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $recordTitleAttribute = 'content';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('content')
                    ->label('Question')
                    ->wrap()
                    ->limit(100),

                TextColumn::make('grade.name')->label('Grade')->sortable()->searchable(),
                TextColumn::make('subject.name')->label('Subject')->sortable()->searchable(),
                TextColumn::make('topic.name')->label('Topic')->sortable()->searchable(),
                TextColumn::make('level.name')->label('Level')->sortable()->searchable(),
                TextColumn::make('pivot.position')->label('Position')->sortable(),
            ])
            ->headerActions([
                // Import and Browse actions are on EditTournament page breadcrumb
            ])
            ->actions([
                DetachAction::make(),
            ])
            ->bulkActions([
                DetachAction::make(),
            ]);
    }
}

