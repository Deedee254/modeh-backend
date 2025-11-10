<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TournamentBattleResource\Pages;
use App\Models\TournamentBattle;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

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
                Action::make('finalize')
                    ->label('Finalize')
                    ->requiresConfirmation()
                    ->action(function (TournamentBattle $record) {
                        // Mark battle completed based on scores if available
                        if ($record->player1_score !== null && $record->player2_score !== null) {
                            if ($record->player1_score > $record->player2_score) {
                                $record->winner_id = $record->player1_id;
                            } elseif ($record->player2_score > $record->player1_score) {
                                $record->winner_id = $record->player2_id;
                            } else {
                                // draw
                                $record->winner_id = null;
                            }
                            $record->status = 'completed';
                            $record->completed_at = now();
                            $record->save();
                        }
                    }),
                Action::make('reset')
                    ->label('Reset')
                    ->requiresConfirmation()
                    ->action(function (TournamentBattle $record) {
                        $record->player1_score = null;
                        $record->player2_score = null;
                        $record->winner_id = null;
                        $record->status = 'scheduled';
                        $record->completed_at = null;
                        $record->save();
                    }),
                Action::make('attach_questions')
                    ->label('Attach Questions')
                    ->form([
                        Forms\Components\FileUpload::make('csv')
                            ->label('CSV / Excel (question ids or full question rows)')
                            ->acceptedFileTypes([
                                'text/csv', '.csv', '.txt',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx'
                            ])
                            ->maxSize(10240)
                            ->helperText('Upload a CSV (or .xlsx) containing either an `id`/`question_id` column to attach existing questions, or a full set of question columns (prompt/type/options/etc.) to create & attach.'),
                        Forms\Components\TextInput::make('question_count')->numeric()->default(10),
                        Forms\Components\Select::make('grade_id')
                            ->label('Grade')
                            ->options(fn () => \App\Models\Grade::query()->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->nullable(),
                        Forms\Components\Select::make('subject_id')
                            ->label('Subject')
                            ->options(fn () => \App\Models\Subject::query()->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->nullable(),
                        Forms\Components\Select::make('topic_id')
                            ->label('Topic')
                            ->options(fn () => \App\Models\Topic::query()->pluck('name', 'id')->toArray())
                            ->searchable()
                            ->nullable(),
                        Forms\Components\Select::make('difficulty')->options([
                            'easy' => 'Easy',
                            'medium' => 'Medium',
                            'hard' => 'Hard'
                        ])->nullable(),
                        Forms\Components\Toggle::make('random')->default(true),
                    ])
                    ->action(function (TournamentBattle $record, array $data) {
                        // When called from Filament, pass the action data (including uploaded file) directly to the controller.
                        $payload = $data ?: [];
                        $res = app()->call([\App\Http\Controllers\Api\AdminTournamentController::class, 'attachQuestionsToBattle'], ['request' => $payload, 'tournament' => $record->tournament, 'battle' => $record]);

                        // Normalize response data from controller (JsonResponse or array)
                        $payload = null;
                        if (is_array($res)) {
                            $payload = $res;
                        } elseif (is_object($res)) {
                            if (method_exists($res, 'getData')) {
                                $payload = $res->getData(true);
                            } elseif (method_exists($res, 'getContent')) {
                                $payload = json_decode($res->getContent(), true);
                            }
                        }

                        if (is_array($payload) && isset($payload['attached'])) {
                            Notification::make()
                                ->success()
                                ->title('Questions attached')
                                ->body('Attached ' . ($payload['attached'] ?? 0) . ' questions.')
                                ->send();
                        } elseif (is_array($payload) && isset($payload['created'])) {
                            Notification::make()
                                ->success()
                                ->title('Questions created & attached')
                                ->body('Created ' . ($payload['created'] ?? 0) . ' questions and attached ' . ($payload['attached'] ?? 0) . '.')
                                ->send();
                        } elseif (is_array($payload) && isset($payload['message'])) {
                            Notification::make()
                                ->danger()
                                ->title('Attach failed')
                                ->body($payload['message'])
                                ->send();
                        }

                        return $payload;
                    }),
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
