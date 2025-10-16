<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BattleResource\Pages;
use App\Models\Battle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Forms\Components\DatePicker;

class BattleResource extends Resource
{
    protected static ?string $model = Battle::class;
    protected static \UnitEnum|string|null $navigationGroup = 'User Engagement';
    protected static ?int $navigationSort = 1;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-sparkles';

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Select::make('initiator_id')->relationship('initiator', 'id')->searchable()->required()->label('Initiator'),
            Select::make('opponent_id')->relationship('opponent', 'id')->searchable()->required()->label('Opponent'),
            Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'accepted' => 'Accepted',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ])->required(),
            Select::make('winner_id')->relationship('winner', 'id')->searchable()->label('Winner'),
            TextInput::make('initiator_points')->numeric()->default(0),
            TextInput::make('opponent_points')->numeric()->default(0),
            TextInput::make('rounds_completed')->numeric()->default(0),
            // One-off purchase price (optional)
            TextInput::make('one_off_price')
                ->numeric()
                ->label('One-off Price')
                ->min(0)
                ->step(0.01),
            DateTimePicker::make('completed_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('initiator.id')->label('Initiator')->searchable(),
                TextColumn::make('opponent.id')->label('Opponent')->searchable(),
                TextColumn::make('one_off_price')
                    ->label('One-off Price')
                    ->formatStateUsing(fn($state) => $state !== null ? number_format($state, 2) : '-')
                    ->sortable(),
                BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'accepted',
                        'primary' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->label('Status')
                    ->sortable(),
                TextColumn::make('winner.id')->label('Winner')->toggleable(),
                TextColumn::make('initiator_points')->label('Initiator Pts')->numeric()->sortable(),
                TextColumn::make('opponent_points')->label('Opponent Pts')->numeric()->sortable(),
                TextColumn::make('rounds_completed')->label('Rounds')->numeric()->sortable(),
                TextColumn::make('completed_at')->dateTime()->since()->sortable(),
                TextColumn::make('created_at')->dateTime()->since()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'accepted' => 'Accepted',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ]),
                Filter::make('created_between')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBattles::route('/'),
            'create' => Pages\CreateBattle::route('/create'),
            'edit' => Pages\EditBattle::route('/{record}/edit'),
            'view' => Pages\ViewBattle::route('/{record}'),
        ];
    }
}