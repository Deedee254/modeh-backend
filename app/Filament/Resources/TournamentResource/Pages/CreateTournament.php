<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\CreateRecord;

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

        // Validate questions
        if (count($this->data['questions']) < 5) {
            $this->addError('questions', 'Tournament must have at least 5 questions.');
            $this->halt();
        }
    }
}