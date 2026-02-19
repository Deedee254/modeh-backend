<?php

namespace App\Filament\Resources\PricingSettingResource\Pages;

use App\Filament\Resources\PricingSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingSettings extends ListRecords
{
    protected static string $resource = PricingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
