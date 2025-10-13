<?php

namespace App\Filament\Resources\QuizMasters\Pages;

use App\Filament\Resources\QuizMasters\QuizMasterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuizMasters extends ListRecords
{
    protected static string $resource = QuizMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
