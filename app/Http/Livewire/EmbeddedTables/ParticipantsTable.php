<?php

namespace App\Http\Livewire\EmbeddedTables;

use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use App\Models\Tournament;

class ParticipantsTable extends Component implements HasTable
{
    use InteractsWithTable;

    public ?int $tournamentId = null;

    public function mount($tournamentId = null)
    {
        $this->tournamentId = $tournamentId;
    }

    protected function getTableQuery()
    {
        return Tournament::find($this->tournamentId)?->participants() ?? null;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('pivot.score')->label('Score')->sortable(),
            TextColumn::make('pivot.rank')->label('Rank')->sortable(),
            TextColumn::make('pivot.completed_at')->label('Completed')->dateTime()->sortable(),
        ];
    }

    public function render()
    {
        return view('livewire.embedded-tables.participants-table');
    }
}
