<?php

namespace App\Filament\Resources\ChatResource\Pages;

use App\Filament\Resources\ChatResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Contracts\TranslatableContentDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\Message;
use App\Models\User;

class ViewMessage extends ViewRecord implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ChatResource::class;

    public function getTitle(): string
    {
        $senderName = $this->record?->name ?? 'Unknown Sender';
        return "Messages from {$senderName}";
    }

    /**
     * Override record resolution to load a User model instead of Message model.
     * The {record} parameter in the route is a User id (sender_id), not a Message id.
     */
    protected function resolveRecord($key): Model
    {
        return User::findOrFail($key);
    }

    // Filament's BasePage already provides required schema/table-related properties.
    // Avoid redeclaring them here to prevent type/visibility mismatches.

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    protected function getTableQuery(): Builder
    {
        // $this->record is a User when accessed from ListMessages index (sender view)
        // The User's id IS the sender_id in the messages table
        $senderId = $this->record?->id;

        return Message::query()
            ->when($senderId, fn ($q) => $q->where('sender_id', $senderId))
            ->latest();
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
            DeleteAction::make(),
        ];
    }

    protected function getTableBulkActions(): array
    {
        return [
            DeleteBulkAction::make(),
        ];
    }

    /**
     * Render the page with the table displayed.
     * ViewRecord doesn't automatically render the table from InteractsWithTable,
     * so we need to call mountInteractsWithTable() and render the table view.
     */
    public function mount($record): void
    {
        parent::mount($record);
        $this->mountInteractsWithTable();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        // Provide the sender id to the view so legacy Livewire sender messages
        // component can mount correctly. This keeps the UI simple and avoids
        // further Filament table rendering issues while we stabilize the page.
        return view('filament.pages.view-message', [
            'senderId' => $this->record?->id,
        ]);
    }
}