<?php

namespace App\Filament\Resources\quiz-masters\Pages;

use App\Filament\Resources\quiz-masters\quiz-masterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class Listquiz-masters extends ListRecords
{
    protected static string $resource = quiz-masterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
