<?php

namespace App\Filament\Resources\quizees\Pages;

use App\Filament\Resources\quizees\quizeeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class Editquizee extends EditRecord
{
    protected static string $resource = quizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
