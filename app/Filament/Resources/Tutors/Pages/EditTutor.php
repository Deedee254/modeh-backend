<?php

namespace App\Filament\Resources\quiz-masters\Pages;

use App\Filament\Resources\quiz-masters\quiz-masterResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class Editquiz-master extends EditRecord
{
    protected static string $resource = quiz-masterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
