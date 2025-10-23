<?php

namespace App\Filament\Resources\TournamentBattleResource\Pages;

use App\Filament\Resources\TournamentBattleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTournamentBattles extends ListRecords
{
    protected static string $resource = TournamentBattleResource::class;

    protected function getActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
