<?php

namespace App\Filament\Resources\TopicResource\Pages;

use App\Filament\Resources\TopicResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateTopic extends CreateRecord
{
    protected static string $resource = TopicResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set the creating admin user id automatically
        $data['created_by'] = Auth::id();

        // Set approval requested timestamp automatically
        $data['approval_requested_at'] = now();

        return $data;
    }
}
