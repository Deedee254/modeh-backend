<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Resources\PackageResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreatePackage extends CreateRecord
{
    protected static string $resource = PackageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['features']) && is_array($data['features'])) {
            $display = [];
            $limits = [];
            foreach ($data['features'] as $item) {
                $name = $item['feature'] ?? null;
                if ($name) {
                    $display[] = $name;
                    $key = (!empty($item['key'])) ? $item['key'] : Str::slug($name);
                    $limits[$key] = array_key_exists('limit', $item) ? $item['limit'] : null;
                }
            }

            $data['features'] = [
                'display' => $display,
                'limits' => $limits,
            ];
        }

        return $data;
    }
}
