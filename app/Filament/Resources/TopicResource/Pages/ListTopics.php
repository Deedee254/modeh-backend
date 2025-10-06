<?php

namespace App\Filament\Resources\TopicResource\Pages;

use App\Filament\Resources\TopicResource;
use App\Models\Topic;
use Filament\Actions\Action;
use Filament\Tables\Filters\Filter;
use Filament\Resources\Pages\ListRecords;

class ListTopics extends ListRecords
{
    protected static string $resource = TopicResource::class;

    protected function getTableActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->action(function (Topic $record) {
                    $record->is_approved = true;
                    $record->approval_requested_at = null;
                    $record->save();
                })
                ->requiresConfirmation()
                ->visible(fn (Topic $record) => !$record->is_approved),
            Action::make('toggleApprove')
                ->label('Toggle Approve')
                ->action(function (Topic $record) {
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
