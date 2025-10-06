<?php

namespace App\Filament\Resources\UserOnboardings\Pages;

use App\Filament\Resources\UserOnboardings\UserOnboardingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUserOnboardings extends ListRecords
{
    protected static string $resource = UserOnboardingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
