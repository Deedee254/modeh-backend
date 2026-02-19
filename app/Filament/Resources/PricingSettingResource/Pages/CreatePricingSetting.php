<?php

namespace App\Filament\Resources\PricingSettingResource\Pages;

use App\Filament\Resources\PricingSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingSetting extends CreateRecord
{
    protected static string $resource = PricingSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
