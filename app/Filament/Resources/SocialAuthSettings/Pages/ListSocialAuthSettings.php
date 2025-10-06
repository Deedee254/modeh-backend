<?php

namespace App\Filament\Resources\SocialAuthSettings\Pages;

use App\Filament\Resources\SocialAuthSettings\SocialAuthSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSocialAuthSettings extends ListRecords
{
    protected static string $resource = SocialAuthSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
