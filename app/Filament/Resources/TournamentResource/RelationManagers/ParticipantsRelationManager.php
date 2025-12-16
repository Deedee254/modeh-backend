<?php

namespace App\Filament\Resources\TournamentResource\RelationManagers;

use App\Models\User;
use App\Models\OneOffPurchase;
use App\Models\Subscription;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Illuminate\Support\Facades\Auth;

class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Tables\Table $table): Tables\Table
    {
        $tournament = $this->getOwnerRecord();

        return $table
            ->columns([
                ImageColumn::make('avatar_url')->circular()->label(''),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('email')->sortable()->searchable(),
                // Show payment status (paid / pending_payment / rejected) as a colored badge
                BadgeColumn::make('pivot.status')
                    ->label('Payment Status')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => [
                        'paid' => 'Paid',
                        'pending_payment' => 'Pending payment',
                        'rejected' => 'Rejected',
                    ][$state] ?? (string) $state)
                    ->colors([
                        'success' => fn ($state): bool => $state === 'paid',
                        'warning' => fn ($state): bool => $state === 'pending_payment',
                        'danger' => fn ($state): bool => $state === 'rejected',
                    ]),
                TextColumn::make('pivot.requested_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('pivot.approved_at')->label('Paid')->dateTime()->sortable(),
            ])
            ->bulkActions([
                DetachAction::make(),
            ]);
    }
}
