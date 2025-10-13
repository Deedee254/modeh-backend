<?php

namespace App\Filament\Resources\Quizees\Pages;

use App\Filament\Resources\Quizees\QuizeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuizees extends ListRecords
{
    protected static string $resource = QuizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
