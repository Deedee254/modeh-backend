<?php

namespace App\Filament\Resources\quizees\Pages;

use App\Filament\Resources\quizees\quizeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class Listquizees extends ListRecords
{
    protected static string $resource = quizeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
