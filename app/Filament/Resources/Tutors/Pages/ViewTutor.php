<?php

namespace App\Filament\Resources\quiz-masters\Pages;

use App\Filament\Resources\quiz-masters\quiz-masterResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class Viewquiz-master extends ViewRecord
{
    protected static string $resource = quiz-masterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
