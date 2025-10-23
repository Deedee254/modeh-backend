<?php

namespace App\Http\Livewire\EmbeddedTables;

use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use App\Models\Tournament;

class QuestionsTable extends Component implements HasTable
{
    use InteractsWithTable;

    public ?int $tournamentId = null;

    public function mount($tournamentId = null)
    {
        $this->tournamentId = $tournamentId;
    }

    protected function getTableQuery()
    {
        return Tournament::find($this->tournamentId)?->questions() ?? null;
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('content')->searchable()->limit(50),
            TextColumn::make('difficulty')->badge()->sortable(),
            TextColumn::make('points')->sortable(),
            TextColumn::make('pivot.position')->label('Order')->sortable(),
        ];
    }

    public function render()
    {
        return view('livewire.embedded-tables.questions-table');
    }
}
