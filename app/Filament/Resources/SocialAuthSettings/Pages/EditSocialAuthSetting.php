<?php

namespace App\Filament\Resources\SocialAuthSettings\Pages;

use App\Filament\Resources\SocialAuthSettings\SocialAuthSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialAuthSetting extends EditRecord
{
    protected static string $resource = SocialAuthSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
