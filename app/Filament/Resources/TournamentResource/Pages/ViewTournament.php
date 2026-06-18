<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Resources\Pages\ViewRecord;

class ViewTournament extends ViewRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => $this->record->status === 'upcoming'),
            Action::make('view_leaderboard')
                ->label('Tournament Leaderboard')
                ->url(fn () => route('filament.admin.resources.tournaments.leaderboard', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
            Action::make('tournament_leaderboard')
                ->label('Tournament Leaderboard')
                ->url(fn () => TournamentResource::getUrl('tournament_leaderboard', ['record' => $this->record]))
                ->visible(fn () => true)
                ->color('info')
                ->icon('heroicon-o-list-bullet'),
        ];
    }

    protected function getInfolistSchema(): array
    {
        return [
            // Header Section - Tournament Information
            Section::make('Tournament Information')
                ->columns(2)
                ->schema([
                    Placeholder::make('name')
                        ->label('Tournament Name')
                        ->content(fn () => $this->record->name)
                        ->columnSpan('full'),
                    
                    Placeholder::make('description')
                        ->label('Description')
                        ->content(fn () => $this->record->description)
                        ->columnSpan('full'),
                    
                    Placeholder::make('status')
                        ->label('Status')
                        ->content(fn () => ucfirst($this->record->status)),
                    
                    Placeholder::make('format')
                        ->label('Format')
                        ->content(fn () => $this->record->format),
                ]),

            // Dates & Pricing Section
            Section::make('Schedule & Pricing')
                ->columns(2)
                ->schema([
                    Placeholder::make('start_date')
                        ->label('Start Date')
                        ->content(fn () => $this->record->start_date?->format('Y-m-d H:i') ?? 'N/A'),
                    
                    Placeholder::make('end_date')
                        ->label('End Date')
                        ->content(fn () => $this->record->end_date?->format('Y-m-d H:i') ?? 'N/A'),
                    
                    Placeholder::make('entry_fee')
                        ->label('Entry Fee')
                        ->content(fn () => '$' . ($this->record->entry_fee ?? '0.00')),
                    
                    Placeholder::make('prize_pool')
                        ->label('Prize Pool')
                        ->content(fn () => '$' . ($this->record->prize_pool ?? '0.00')),
                ]),

            // Configuration Section
            Section::make('Tournament Configuration')
                ->columns(2)
                ->schema([
                    Placeholder::make('question_count')
                        ->label('Questions')
                        ->content(fn () => (string) ($this->record->question_count ?? '10')),
                    
                    Placeholder::make('per_question_seconds')
                        ->label('Time/Q')
                        ->content(fn () => ($this->record->per_question_seconds ?? '30') . 's'),
                    
                    Placeholder::make('duration_days')
                        ->label('Duration')
                        ->content(fn () => ($this->record->duration_days ?? '7') . ' days'),
                    
                    Placeholder::make('tie_breaker')
                        ->label('Tie-Breaker')
                        ->content(fn () => str_replace('_', ' ', $this->record->tie_breaker ?? 'score_then_duration')),
                ]),

            // Statistics Section
            Section::make('Tournament Statistics')
                ->columns(2)
                ->schema([
                    Placeholder::make('participants_count')
                        ->label('Participants')
                        ->content(fn () => (string) ($this->record->participants_count ?? '0')),
                    
                    Placeholder::make('questions_count')
                        ->label('Questions')
                        ->content(fn () => (string) ($this->record->questions_count ?? '0')),
                ]),

            // Sponsor Section
            Section::make('Sponsor Information')
                ->columns(2)
                ->visible(fn () => $this->record->sponsor_id !== null)
                ->schema([
                    Placeholder::make('sponsor_name')
                        ->label('Sponsor Name')
                        ->content(fn () => $this->record->sponsor?->name ?? 'N/A'),
                    
                    Placeholder::make('sponsor_website')
                        ->label('Website')
                        ->content(fn () => $this->record->sponsor?->website_url ?? 'N/A'),
                ]),
        ];
    }
}
