<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use App\Models\Tournament;
use App\Models\TournamentParticipant;

class PendingRegistrations extends Page implements Tables\Contracts\HasTable
{
    use InteractsWithTable;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Pending Registrations';
    protected static ?int $navigationSort = 2;
    protected string $view = 'filament.pages.pending-registrations';

    public function getTitle(): string
    {
        return 'Tournament Registrations Pending Payment';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => TournamentParticipant::query()
                ->where('tournament_participants.status', 'pending_payment')
                ->join('users', 'users.id', '=', 'tournament_participants.user_id')
                ->join('tournaments', 'tournaments.id', '=', 'tournament_participants.tournament_id')
                ->select('tournament_participants.*', 'users.name as user_name', 'users.email as user_email', 'tournaments.name as tournament_name'))
            ->columns([
                Tables\Columns\TextColumn::make('user_name')->label('User')->searchable(),
                Tables\Columns\TextColumn::make('user_email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('tournament_name')->label('Tournament')->searchable(),
                Tables\Columns\TextColumn::make('requested_at')->label('Requested')->dateTime()->sortable(),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->action(function (array $record) {
                        try {
                            app()->call([\App\Http\Controllers\Api\TournamentController::class, 'approveRegistration'], [
                                'request' => request(),
                                'tournament' => Tournament::find($record['tournament_id']),
                                'userId' => $record['user_id'],
                            ]);
                            Notification::make()->title('Approved')->success()->send();
                            $this->table->getQuery()->where('id', '<', 0); // trigger refresh
                        } catch (\Exception $e) {
                            Notification::make()->title('Failed to approve')->danger()->send();
                        }
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (array $record) {
                        try {
                            app()->call([\App\Http\Controllers\Api\TournamentController::class, 'rejectRegistration'], [
                                'request' => request(),
                                'tournament' => Tournament::find($record['tournament_id']),
                                'userId' => $record['user_id'],
                            ]);
                            Notification::make()->title('Rejected')->success()->send();
                            $this->table->getQuery()->where('id', '<', 0);
                        } catch (\Exception $e) {
                            Notification::make()->title('Failed to reject')->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('approve_selected')
                    ->label('Approve Selected')
                    ->action(function (array $records) {
                        foreach ($records as $r) {
                            try {
                                app()->call([\App\Http\Controllers\Api\TournamentController::class, 'approveRegistration'], [
                                    'request' => request(),
                                    'tournament' => Tournament::find($r['tournament_id']),
                                    'userId' => $r['user_id'],
                                ]);
                            } catch (\Exception $e) {
                                // continue
                            }
                        }
                        Notification::make()->title('Approved selected')->success()->send();
                    }),
                BulkAction::make('reject_selected')
                    ->label('Reject Selected')
                    ->action(function (array $records) {
                        foreach ($records as $r) {
                            try {
                                app()->call([\App\Http\Controllers\Api\TournamentController::class, 'rejectRegistration'], [
                                    'request' => request(),
                                    'tournament' => Tournament::find($r['tournament_id']),
                                    'userId' => $r['user_id'],
                                ]);
                            } catch (\Exception $e) {
                                // continue
                            }
                        }
                        Notification::make()->title('Rejected selected')->success()->send();
                    }),
            ]);
    }
}
