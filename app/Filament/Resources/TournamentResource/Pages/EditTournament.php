<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('generate_matches')
                ->action(fn () => $this->record->generateMatches())
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'upcoming')
                ->color('success')
                ->icon('heroicon-o-play'),
            Action::make('view_leaderboard')
                ->url(fn () => route('admin.tournaments.leaderboard', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeSave(): void
    {
        // Validate dates if they were changed
        if ($this->record->isDirty(['start_date', 'end_date'])) {
            $startDate = \Carbon\Carbon::parse($this->data['start_date']);
            $endDate = \Carbon\Carbon::parse($this->data['end_date']);

            if ($startDate->isBefore(now()) && $this->record->status === 'upcoming') {
                $this->addError('start_date', 'Start date must be in the future for upcoming tournaments.');
                $this->halt();
            }

            if ($endDate->isBefore($startDate)) {
                $this->addError('end_date', 'End date must be after start date.');
                $this->halt();
            }
        }

        // Validate questions
        if (count($this->data['questions']) < 5) {
            $this->addError('questions', 'Tournament must have at least 5 questions.');
            $this->halt();
        }

        // Prevent editing of active or completed tournaments
        if ($this->record->status !== 'upcoming') {
            $this->addError('*', 'Cannot edit active or completed tournaments.');
            $this->halt();
        }
    }
}