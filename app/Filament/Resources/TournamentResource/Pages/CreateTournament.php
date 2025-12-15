<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'upcoming';
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function beforeCreate(): void
    {
        // Validate dates
        $startDate = \Carbon\Carbon::parse($this->data['start_date']);
        $endDate = \Carbon\Carbon::parse($this->data['end_date']);

        if ($startDate->isBefore(now())) {
            $this->addError('start_date', 'Start date must be in the future.');
            $this->halt();
        }

        if ($endDate->isBefore($startDate)) {
            $this->addError('end_date', 'End date must be after start date.');
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        // Show success notification
        Notification::make()
            ->success()
            ->title('Tournament Created')
            ->body('Tournament ready! You can now add questions from the bank or import a CSV file.')
            ->send();
    }
}