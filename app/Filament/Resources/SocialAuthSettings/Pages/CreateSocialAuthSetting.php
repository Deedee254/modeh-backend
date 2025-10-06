<?php

namespace App\Filament\Resources\SocialAuthSettings\Pages;

use App\Filament\Resources\SocialAuthSettings\SocialAuthSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialAuthSetting extends CreateRecord
{
    protected static string $resource = SocialAuthSettingResource::class;
}
