<?php

namespace App\Filament\Resources\SiteSettingResource\Pages;

use App\Filament\Resources\SiteSettingResource;
use App\Models\SiteSetting;
use Filament\Resources\Pages\EditRecord;

class EditSiteSettings extends EditRecord
{
    protected static string $resource = SiteSettingResource::class;

    public function getRecord(): \Illuminate\Database\Eloquent\Model
    {
        return SiteSetting::first();
    }

    // Override mount so Filament/Laravel won't try to inject a missing route parameter.
    // Ensure a record exists and pass its primary key to the parent mount (which expects the record id).
    public function mount(int | string | null $record = null): void
    {
        if ($record === null) {
            $recordModel = SiteSetting::firstOrCreate([]);

            $record = $recordModel->getKey();
        }

        parent::mount($record);
    }
}
