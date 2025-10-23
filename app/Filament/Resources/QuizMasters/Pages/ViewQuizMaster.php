<?php

namespace App\Filament\Resources\QuizMasters\Pages;

use App\Filament\Resources\QuizMasters\QuizMasterResource;
    use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuizMaster extends ViewRecord
{
    protected static string $resource = QuizMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
