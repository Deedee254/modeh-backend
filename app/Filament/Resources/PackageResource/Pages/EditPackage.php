<?php

namespace App\Filament\Resources\PackageResource\Pages;

use App\Filament\Resources\PackageResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
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

    public function mount($record): void
    {
        // Let Filament perform its normal mount first (sets $this->record etc.)
        parent::mount($record);

        // If the package has structured features (display + limits), convert them
        // into the repeater-friendly shape: [ ['feature'=>name, 'limit'=>int|null], ... ]
        $features = $this->record->features ?? [];
        $items = [];
        if (is_array($features)) {
            $display = $features['display'] ?? $features;
            $limits = $features['limits'] ?? [];
            foreach ($display as $name) {
                $slug = \Illuminate\Support\Str::slug($name);
                $items[] = [
                    'feature' => $name,
                    // populate key with the slug so the repeater shows something sensible;
                    // limits are looked up by slug when possible
                    'key' => $slug,
                    'limit' => $limits[$slug] ?? null,
                ];
            }
        }

        // Pre-fill the form repeater with the converted items so the admin sees
        // feature names and existing limits in the UI.
        $this->form->fill([
            'features' => $items,
        ]);
    }
}
