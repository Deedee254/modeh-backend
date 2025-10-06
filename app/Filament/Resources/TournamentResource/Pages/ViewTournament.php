<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class ViewTournament extends ViewRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn () => $this->record->status === 'upcoming'),
            Actions\Action::make('generate_matches')
                ->action(fn () => $this->record->generateMatches())
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'upcoming')
                ->color('success')
                ->icon('heroicon-o-play'),
            Actions\Action::make('view_leaderboard')
                ->url(fn () => route('admin.tournaments.leaderboard', $this->record))
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'details' => Tables\Actions\Tab::make('Details')
                ->schema([
                    // Tournament details form schema here...
                    Forms\Components\TextInput::make('name')
                        ->disabled(),
                    Forms\Components\RichEditor::make('description')
                        ->disabled(),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_date')
                                ->disabled(),
                            Forms\Components\DateTimePicker::make('end_date')
                                ->disabled(),
                            Forms\Components\TextInput::make('prize_pool')
                                ->disabled()
                                ->prefix('$'),
                            Forms\Components\TextInput::make('entry_fee')
                                ->disabled()
                                ->prefix('$'),
                        ]),
                ]),

            'participants' => Tables\Actions\Tab::make('Participants')
                ->schema([
                    Tables\Actions\ViewAction::make('participants_table')
                        ->table(fn () => 
                            Table::make()
                                ->relationship('participants')
                                ->columns([
                                    Tables\Columns\TextColumn::make('name')
                                        ->searchable()
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('email')
                                        ->searchable()
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('pivot.score')
                                        ->label('Score')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('pivot.rank')
                                        ->label('Rank')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('pivot.completed_at')
                                        ->label('Completed')
                                        ->dateTime()
                                        ->sortable(),
                                ])
                        )
                ]),

            'battles' => Tables\Actions\Tab::make('Battles')
                ->schema([
                    Tables\Actions\ViewAction::make('battles_table')
                        ->table(fn () => 
                            Table::make()
                                ->relationship('battles')
                                ->columns([
                                    Tables\Columns\TextColumn::make('round')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('player1.name')
                                        ->label('Player 1')
                                        ->searchable(),
                                    Tables\Columns\TextColumn::make('player2.name')
                                        ->label('Player 2')
                                        ->searchable(),
                                    Tables\Columns\TextColumn::make('player1_score')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('player2_score')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('winner.name')
                                        ->label('Winner')
                                        ->searchable(),
                                    Tables\Columns\TextColumn::make('status')
                                        ->badge()
                                        ->color(fn (string $state): string => match ($state) {
                                            'upcoming' => 'warning',
                                            'ongoing' => 'success',
                                            'completed' => 'gray',
                                            default => 'gray',
                                        }),
                                    Tables\Columns\TextColumn::make('scheduled_at')
                                        ->dateTime()
                                        ->sortable(),
                                ])
                        )
                ]),

            'questions' => Tables\Actions\Tab::make('Questions')
                ->schema([
                    Tables\Actions\ViewAction::make('questions_table')
                        ->table(fn () => 
                            Table::make()
                                ->relationship('questions')
                                ->columns([
                                    Tables\Columns\TextColumn::make('content')
                                        ->searchable()
                                        ->limit(50),
                                    Tables\Columns\TextColumn::make('difficulty')
                                        ->badge()
                                        ->color(fn (string $state): string => match ($state) {
                                            'easy' => 'success',
                                            'medium' => 'warning',
                                            'hard' => 'danger',
                                            default => 'gray',
                                        }),
                                    Tables\Columns\TextColumn::make('points')
                                        ->sortable(),
                                    Tables\Columns\TextColumn::make('pivot.position')
                                        ->label('Order')
                                        ->sortable(),
                                ])
                                ->defaultSort('pivot.position', 'asc')
                        )
                ]),

            'sponsor' => Tables\Actions\Tab::make('Sponsor')
                ->schema([
                    Forms\Components\Card::make()
                        ->schema([
                            Forms\Components\TextInput::make('sponsor.name')
                                ->disabled(),
                            Forms\Components\TextInput::make('sponsor_banner_url')
                                ->disabled(),
                            Forms\Components\Textarea::make('sponsor_message')
                                ->disabled(),
                            Forms\Components\TextInput::make('sponsor.website_url')
                                ->disabled(),
                        ])
                        ->visible(fn () => $this->record->sponsor_id !== null)
                ])
                ->visible(fn () => $this->record->sponsor_id !== null),
        ];
    }
}