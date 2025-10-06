<?php

namespace App\Filament\Resources\ChatResource\Pages;

use App\Filament\Resources\ChatResource;
use Filament\Resources\Pages\ViewRecord;
use App\Events\MessageSent;

class ViewMessage extends ViewRecord
{
    protected static string $resource = ChatResource::class;
    
    protected function afterSave(): void
    {
        $message = $this->record;
        broadcast(new MessageSent($message))->toOthers();
    }
}