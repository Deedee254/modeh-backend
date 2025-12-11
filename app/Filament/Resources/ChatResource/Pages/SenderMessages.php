<?php

namespace App\Filament\Resources\ChatResource\Pages;

use App\Filament\Resources\ChatResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use App\Models\Message;
use Illuminate\Support\Str;

class SenderMessages extends ListRecords
{
    protected static string $resource = ChatResource::class;

    /**
     * Build a table that lists messages filtered by sender id taken from the
     * route parameter `senderId`.
     */
    public function table(Tables\Table $table): Tables\Table
    {
    $senderId = request()->route('record');

        return $table
            ->query(Message::query()->when($senderId, fn($q) => $q->where('sender_id', $senderId))->latest())
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->label('Message')
                    ->html()
                    ->formatStateUsing(fn ($state) => '<span title="'.e($state).'">'.e(Str::limit($state, 140)).'</span>')
                    ->wrap(false),
                Tables\Columns\TextColumn::make('attachments')
                    ->label('Attachments')
                    ->html()
                    ->formatStateUsing(fn ($state) => (count((array) $state) > 0) ? '<span title="Has attachments">📎</span>' : ''),
                Tables\Columns\IconColumn::make('is_read')->label('Read')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Type')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Sent At')->dateTime()->sortable(),
            ])
            ->actions([
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public function getTitle(): string
    {
    $senderId = request()->route('record');
        $name = optional(\App\Models\User::find($senderId))->name ?? 'Sender';
        return "Messages from {$name}";
    }
}
