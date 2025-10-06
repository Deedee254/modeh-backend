<?php

namespace App\Filament\Resources\UserOnboardings\Pages;

use App\Filament\Resources\UserOnboardings\UserOnboardingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUserOnboarding extends ViewRecord
{
    protected static string $resource = UserOnboardingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
