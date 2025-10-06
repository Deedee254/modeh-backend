<?php

namespace App\Filament\Resources\SocialAuthSettings\Pages;

use App\Filament\Resources\SocialAuthSettings\SocialAuthSettingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSocialAuthSetting extends ViewRecord
{
    protected static string $resource = SocialAuthSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
