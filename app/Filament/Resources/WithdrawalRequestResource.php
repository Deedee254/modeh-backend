<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Models\WithdrawalRequest;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\DB;

class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';
    protected static \UnitEnum|string|null $navigationGroup = 'User Earnings';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('quiz-master_id')->disabled(),
            TextInput::make('amount')->disabled(),
            TextInput::make('method')->disabled(),
            TextInput::make('status'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            \Filament\Tables\Columns\TextColumn::make('quiz-master.name')->label('quiz-master'),
            \Filament\Tables\Columns\TextColumn::make('amount')->money('KES'),
            \Filament\Tables\Columns\TextColumn::make('method'),
            \Filament\Tables\Columns\TextColumn::make('status'),
            \Filament\Tables\Columns\TextColumn::make('created_at')->date(),
        ])->actions([
            \Filament\Tables\Actions\Action::make('approve')
                ->label('Approve')
                ->requiresConfirmation()
                ->color('primary')
                ->action(function (WithdrawalRequest $record, array $data = []) {
                    $user = auth()->user();
                    DB::transaction(function () use ($record, $user) {
                        $record->status = 'approved';
                        $record->processed_by_admin_id = $user->id ?? null;
                        $record->save();
                    });
                    try { event(new \App\Events\WithdrawalRequestUpdated($record->quiz_master_id, $record->toArray())); } catch (\Throwable $_) {}
                }),
            \Filament\Tables\Actions\Action::make('reject_refund')
                ->label('Reject + Refund')
                ->requiresConfirmation()
                ->color('danger')
                ->action(function (WithdrawalRequest $record, array $data = []) {
                    // Refund the amount back to quiz-master available balance
                    $wallet = null;
                    DB::transaction(function () use ($record, &$wallet) {
                        $record->status = 'rejected';
                        $record->save();

                        $w = \App\Models\Wallet::where('user_id', $record->quiz_master_id)->lockForUpdate()->first();
                        if (!$w) {
                            $w = \App\Models\Wallet::create(['user_id' => $record->quiz_master_id, 'available' => 0, 'pending' => 0, 'lifetime_earned' => 0]);
                        }
                        $w->available = bcadd($w->available, $record->amount, 2);
                        $w->save();
                        $wallet = $w;
                    });
                    try { event(new \App\Events\WithdrawalRequestUpdated($record->quiz_master_id, $record->toArray())); } catch (\Throwable $_) {}
                    if ($wallet) { try { event(new \App\Events\WalletUpdated($wallet->toArray(), $record->quiz_master_id)); } catch (\Throwable $_) {} }
                }),
            \Filament\Tables\Actions\Action::make('mark_paid')
                ->label('Mark as Paid')
                ->requiresConfirmation()
                ->color('success')
                ->action(function (WithdrawalRequest $record, array $data = []) {
                    $user = auth()->user();
                    DB::transaction(function () use ($record, $user) {
                        $record->status = 'paid';
                        $record->paid_at = now();
                        $record->processed_by_admin_id = $user->id ?? null;
                        $record->save();
                    });
                    try { event(new \App\Events\WithdrawalRequestUpdated($record->quiz_master_id, $record->toArray())); } catch (\Throwable $_) {}
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawalRequests::route('/'),
            'edit' => Pages\EditWithdrawalRequest::route('/{record}/edit'),
        ];
    }
}
