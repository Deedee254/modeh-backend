<?php

namespace App\Filament\Resources\ChatResource\Pages;

use App\Filament\Resources\ChatResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Actions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class ListMessages extends ListRecords
{
    protected static string $resource = ChatResource::class;

    /**
     * Override table() to display distinct senders aggregated from messages
     * instead of individual message records.
     */
    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->query(
                // Query distinct senders with aggregated counts
                User::query()
                    ->select([
                        'users.id',
                        'users.name',
                        'users.email',
                        DB::raw('COUNT(m.id) as messages_count'),
                        DB::raw('SUM(CASE WHEN m.is_read = 0 THEN 1 ELSE 0 END) as unread_count'),
                        DB::raw('MAX(m.created_at) as last_message_at'),
                    ])
                    ->leftJoin('messages as m', 'users.id', '=', 'm.sender_id')
                    ->groupBy('users.id', 'users.name', 'users.email')
                    ->orderByDesc('last_message_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Sender')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('messages_count')->label('Messages')->sortable(),
                Tables\Columns\TextColumn::make('unread_count')->label('Unread')->sortable(),
                Tables\Columns\TextColumn::make('last_message_at')->label('Last message')->dateTime()->sortable(),
            ])
            ->actions([
                Actions\Action::make('view')
                    ->label('View')
                    ->url(fn ($record) => route('filament.admin.resources.chats.view', ['record' => $record->id]))
                    ->openUrlInNewTab(false),
            ])
            ->bulkActions([
                // Optionally add bulk actions for senders (e.g., block, archive)
            ]);
    }
}