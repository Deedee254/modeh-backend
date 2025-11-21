<?php

namespace App\Filament\Resources\SubjectResource\Pages;

use App\Filament\Resources\SubjectResource;
use App\Models\Subject;
use Filament\Actions\Action;
use Filament\Tables\Filters\Filter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubjects extends ListRecords
{
    protected static string $resource = SubjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add Subject'),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->action(function (Subject $record) {
                    $record->is_approved = true;
                    $record->approval_requested_at = null;
                    $record->save();
                })
                ->requiresConfirmation()
                ->visible(fn (Subject $record) => !$record->is_approved),
            Action::make('toggleApprove')
                ->label('Toggle Approve')
                ->action(function (Subject $record) {
                    $record->is_approved = !$record->is_approved;
                    $record->save();
                })
                ->requiresConfirmation(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Filter::make('approval_requested')
                ->label('Approval requested')
                ->query(fn ($query) => $query->whereNotNull('approval_requested_at')),
        ];
    }
}
