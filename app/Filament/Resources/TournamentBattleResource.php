<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TournamentBattleResource\Pages;
use App\Models\TournamentBattle;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class TournamentBattleResource extends Resource
{
    protected static ?string $model = TournamentBattle::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static \UnitEnum|string|null $navigationGroup = 'Tournaments';
    protected static ?int $navigationSort = 2;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()->schema([
                Forms\Components\Select::make('tournament_id')->relationship('tournament', 'name')->required(),
                Forms\Components\TextInput::make('round')->numeric()->required(),
                Forms\Components\Select::make('player1_id')->relationship('player1', 'name')->required(),
                Forms\Components\Select::make('player2_id')->relationship('player2', 'name')->required(),
                Forms\Components\Select::make('winner_id')->relationship('winner', 'name')->nullable(),
                Forms\Components\TextInput::make('player1_score')->numeric()->nullable(),
                Forms\Components\TextInput::make('player2_score')->numeric()->nullable(),
                Forms\Components\Select::make('status')->options([
                    'scheduled' => 'Scheduled',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed'
                ])->required(),
                Forms\Components\DateTimePicker::make('scheduled_at')->nullable(),
                Forms\Components\DateTimePicker::make('completed_at')->nullable(),
            ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('id')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('tournament.name')->label('Tournament')->searchable(),
                \Filament\Tables\Columns\TextColumn::make('round')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('player1.name')->label('Player 1')->searchable(),
                \Filament\Tables\Columns\TextColumn::make('player2.name')->label('Player 2')->searchable(),
                \Filament\Tables\Columns\TextColumn::make('player1_score')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('player2_score')->sortable(),
                \Filament\Tables\Columns\TextColumn::make('winner.name')->label('Winner')->searchable(),
                \Filament\Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                \Filament\Tables\Columns\TextColumn::make('scheduled_at')->dateTime(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')->options([
                    'scheduled' => 'Scheduled',
                    'in_progress' => 'In Progress',
                    'completed' => 'Completed',
                ]),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTournamentBattles::route('/'),
            'create' => Pages\CreateTournamentBattle::route('/create'),
            'edit' => Pages\EditTournamentBattle::route('/{record}/edit'),
        ];
    }
}
