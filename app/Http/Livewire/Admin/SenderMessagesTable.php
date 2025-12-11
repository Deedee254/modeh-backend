<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Message;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class SenderMessagesTable extends Component implements HasTable, HasSchemas
{
    use InteractsWithTable;
    use InteractsWithSchemas;

    public ?int $senderId = null;


    public function mount($senderId = null)
    {
        $this->senderId = $senderId;
    }

    protected function getTableQuery(): Builder
    {
        return Message::query()->when($this->senderId, fn ($q) => $q->where('sender_id', $this->senderId))->latest();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('content')
                ->label('Message')
                ->html()
                ->formatStateUsing(fn ($state) => '<span title="'.e($state).'">'.e(\Illuminate\Support\Str::limit($state, 140)).'</span>')
                ->wrap(false),
            TextColumn::make('attachments')
                ->label('Attachments')
                ->html()
                ->formatStateUsing(fn ($state) => (count((array) $state) > 0) ? '<span title="Has attachments">📎</span>' : ''),
            IconColumn::make('is_read')->label('Read')->boolean()->sortable(),
            TextColumn::make('type')->label('Type')->sortable(),
            TextColumn::make('created_at')->label('Sent At')->dateTime()->sortable(),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            \Filament\Actions\DeleteAction::make(),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            \Filament\Actions\DeleteBulkAction::make(),
        ];
    }

    public function render()
    {
        return view('livewire.admin.sender-messages-table');
    }

    /**
     * Provide a null translatable content driver to satisfy the HasTable contract
     * used by Filament's tables integration in this Livewire component.
     */
    public function makeFilamentTranslatableContentDriver(): ?\Filament\Support\Contracts\TranslatableContentDriver
    {
        return null;
    }
}
