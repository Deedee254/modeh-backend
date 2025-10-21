<?php

namespace App\Filament\Resources\QuestionResource\Pages;

use App\Filament\Resources\QuestionResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQuestions extends ListRecords
{
    protected static string $resource = QuestionResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            \Filament\Actions\Action::make('approve')
                ->label('Approve')
                ->action(function ($record) {
                    $record->is_approved = true;
                    $record->approval_requested_at = null;
                    $record->save();
                })
                ->requiresConfirmation()
                ->visible(fn ($record) => !$record->is_approved),
            \Filament\Actions\Action::make('toggleApprove')
                ->label('Toggle Approve')
                ->action(function ($record) {
                    $record->is_approved = !$record->is_approved;
                    $record->save();
                })
                ->requiresConfirmation(),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            \Filament\Tables\Filters\Filter::make('approval_requested')
                ->label('Approval requested')
                ->query(fn ($query) => $query->whereNotNull('approval_requested_at')),
        ];
    }
}
