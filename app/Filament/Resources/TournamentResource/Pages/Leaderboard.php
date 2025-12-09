<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use App\Models\Tournament;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;

class Leaderboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TournamentResource::class;

    // Use the existing blade created earlier
    protected string $view = 'filament.pages.tournaments.leaderboard';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                $this->getRecord()->participants()->getQuery()->orderByDesc('tournament_participants.score')->orderBy('tournament_participants.rank')
            )
            ->columns([
                Tables\Columns\TextColumn::make('pivot.rank')
                    ->label('Rank')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Participant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.score')
                    ->label('Score')
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono']),

                Tables\Columns\TextColumn::make('pivot.completed_at')
                    ->label('Completed At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('pivot.status')
                    ->label('Status')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('status')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'active' => 'Active',
                                'completed' => 'Completed',
                                'disqualified' => 'Disqualified',
                            ])
                            ->placeholder('Any'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['status'] ?? null, fn (Builder $q, $s) => $q->where('tournament_participants.status', $s));
                    }),

                Tables\Filters\Filter::make('score_range')
                    ->form([
                        Forms\Components\TextInput::make('min_score')->label('Min score')->numeric(),
                        Forms\Components\TextInput::make('max_score')->label('Max score')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(isset($data['min_score']) && $data['min_score'] !== null, fn (Builder $q, $v) => $q->where('tournament_participants.score', '>=', $v))
                            ->when(isset($data['max_score']) && $data['max_score'] !== null, fn (Builder $q, $v) => $q->where('tournament_participants.score', '<=', $v));
                    }),
            ]);
    }

    public function getRecord(): Tournament
    {
        return static::$resource::getModel()::find($this->record);
    }
}
