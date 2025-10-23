<?php

namespace App\Filament\Resources\TournamentBattleResource\Pages;

use App\Filament\Resources\TournamentBattleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\CreateRecord;

class CreateTournamentBattle extends CreateRecord
{
    protected static string $resource = TournamentBattleResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
