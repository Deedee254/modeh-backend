<?php

namespace App\Filament\Resources\Quizees\Pages;

use App\Filament\Resources\Quizees\QuizeeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditQuizee extends EditRecord
{
    protected static string $resource = QuizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
