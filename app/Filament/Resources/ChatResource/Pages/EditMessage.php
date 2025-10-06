<?php

namespace App\Filament\Resources\ChatResource\Pages;

use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\ChatResource;

class EditMessage extends EditRecord
{
    protected static string $resource = ChatResource::class;
}
