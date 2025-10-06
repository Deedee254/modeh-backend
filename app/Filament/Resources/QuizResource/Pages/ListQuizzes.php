<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\QuizResource;
use App\Models\Quiz;
use Filament\Actions\Action;
use Filament\Tables\Filters\Filter;
use Filament\Resources\Pages\ListRecords;

class ListQuizzes extends ListRecords
{
    protected static string $resource = QuizResource::class;

    protected function getTableActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->action(function (Quiz $record) {
                    $record->is_approved = true;
                    $record->approval_requested_at = null;
                    $record->save();
                })
                ->requiresConfirmation()
                ->visible(fn (Quiz $record) => !$record->is_approved),
            Action::make('toggleApprove')
                ->label('Toggle Approve')
                ->action(function (Quiz $record) {
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
