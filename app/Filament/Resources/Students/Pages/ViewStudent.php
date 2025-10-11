<?php

namespace App\Filament\Resources\quizees\Pages;

use App\Filament\Resources\quizees\quizeeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class Viewquizee extends ViewRecord
{
    protected static string $resource = quizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
