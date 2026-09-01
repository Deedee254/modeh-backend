<?php

namespace App\Http\Livewire\EmbeddedTables;

use App\Models\Tournament;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Component;

class BattlesTable extends Component implements HasTable
{
    use InteractsWithTable;

    public ?int $tournamentId = null;

    public function mount($tournamentId = null)
    {
        $this->tournamentId = $tournamentId;
    }

    protected function getTableQuery()
    {
        return Tournament::find($this->tournamentId)?->battles() ?? null;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('round')->sortable(),
            TextColumn::make('player1.name')->label('Player 1')->searchable(),
            TextColumn::make('player2.name')->label('Player 2')->searchable(),
            TextColumn::make('player1_score')->sortable(),
            TextColumn::make('player2_score')->sortable(),
            TextColumn::make('winner.name')->label('Winner')->searchable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('scheduled_at')->dateTime()->sortable(),
        ];
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    public function render()
    {
        return view('livewire.embedded-tables.battles-table');
    }
}
