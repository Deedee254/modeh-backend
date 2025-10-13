<?php

namespace App\Filament\Resources\Quizees\Pages;

use App\Filament\Resources\Quizees\QuizeeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuizee extends ViewRecord
{
    protected static string $resource = QuizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
