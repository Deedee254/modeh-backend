<?php

namespace App\Filament\Resources\TournamentResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Auth;

class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                IconColumn::make('avatar')->avatar()->label(''),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('email')->sortable()->searchable(),
                TextColumn::make('pivot.status')->label('Status')->sortable(),
                TextColumn::make('pivot.requested_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('pivot.approved_at')->label('Approved')->dateTime()->sortable(),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->visible(fn (User $record): bool => ($record->pivot->status ?? '') === 'pending')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $tournament = $this->getOwnerRecord();
                        try {
                            // Call central controller method so all side-effects run there
                            app()->call([\App\Http\Controllers\Api\TournamentController::class, 'approveRegistration'], [
                                'request' => request(),
                                'tournament' => $tournament,
                                'userId' => $record->id,
                            ]);
                            $this->refresh();
                        } catch (\Exception $e) {
                            $this->notify('danger', 'Failed to approve registration');
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x')
                    ->visible(fn (User $record): bool => ($record->pivot->status ?? '') === 'pending')
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        $tournament = $this->getOwnerRecord();
                        try {
                            app()->call([\App\Http\Controllers\Api\TournamentController::class, 'rejectRegistration'], [
                                'request' => request(),
                                'tournament' => $tournament,
                                'userId' => $record->id,
                            ]);
                            $this->refresh();
                        } catch (\Exception $e) {
                            $this->notify('danger', 'Failed to reject registration');
                        }
                    }),
            ])
            ->bulkActions([
                DeleteAction::make(),
            ]);
    }
}
