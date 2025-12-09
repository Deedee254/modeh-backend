<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;

class ViewTournament extends ViewRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => $this->record->status === 'upcoming'),
            Action::make('generate_matches')
                ->action(fn () => $this->record->generateMatches())
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'upcoming')
                ->color('success')
                ->icon('heroicon-o-play'),
            Action::make('view_leaderboard')
                ->url(fn () => route('admin.tournaments.leaderboard', $this->record))
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    protected function getInfolistSchema(): array
    {
        return [
            // Details
            TextEntry::make('name'),
            TextEntry::make('description'),
            Grid::make(2)
                ->schema([
                    TextEntry::make('start_date'),
                    TextEntry::make('end_date'),
                    TextEntry::make('prize_pool')
                        ->prefix('$'),
                    TextEntry::make('entry_fee')
                        ->prefix('$'),
                ]),

            // Participants
            TextEntry::make('participants_count')
                ->label('Total Participants'),

            // Battles
            TextEntry::make('battles_count')
                ->label('Total Battles'),

            // Rounds
            TextEntry::make('current_round')
                ->label('Current Round'),

            // Questions
            TextEntry::make('questions_count')
                ->label('Total Questions'),

            // Sponsor
            TextEntry::make('sponsor.name'),
            TextEntry::make('sponsor_banner_url'),
            TextEntry::make('sponsor_message'),
            TextEntry::make('sponsor.website_url')
                ->visible(fn () => $this->record->sponsor_id !== null),
        ];
    }
}