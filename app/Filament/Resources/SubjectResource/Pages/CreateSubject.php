<?php

namespace App\Filament\Resources\SubjectResource\Pages;

use App\Filament\Resources\SubjectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateSubject extends CreateRecord
{
    protected static string $resource = SubjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set the creating admin user id automatically
        $data['created_by'] = Auth::id();
        // Set approval requested timestamp automatically
        $data['approval_requested_at'] = now();

        return $data;
    }
}
