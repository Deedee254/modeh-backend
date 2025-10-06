<?php

namespace App\Filament\Resources\TournamentBattleResource\Pages;

use App\Filament\Resources\TournamentBattleResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTournamentBattle extends EditRecord
{
    protected static string $resource = TournamentBattleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
