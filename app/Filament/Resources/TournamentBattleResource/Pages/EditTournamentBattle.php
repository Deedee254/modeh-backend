<?php

namespace App\Filament\Resources\TournamentBattleResource\Pages;

use App\Filament\Resources\TournamentBattleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTournamentBattle extends EditRecord
{
    protected static string $resource = TournamentBattleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
