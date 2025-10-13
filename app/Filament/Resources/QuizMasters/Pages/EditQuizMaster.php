<?php

namespace App\Filament\Resources\QuizMasters\Pages;

use App\Filament\Resources\QuizMasters\QuizMasterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditQuizMaster extends EditRecord
{
    protected static string $resource = QuizMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
