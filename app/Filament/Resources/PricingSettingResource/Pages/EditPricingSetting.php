<?php

namespace App\Filament\Resources\PricingSettingResource\Pages;

use App\Filament\Resources\PricingSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPricingSetting extends EditRecord
{
    protected static string $resource = PricingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
