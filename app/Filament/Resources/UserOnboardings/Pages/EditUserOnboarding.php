<?php

namespace App\Filament\Resources\UserOnboardings\Pages;

use App\Filament\Resources\UserOnboardings\UserOnboardingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUserOnboarding extends EditRecord
{
    protected static string $resource = UserOnboardingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
