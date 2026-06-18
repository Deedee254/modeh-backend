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

class TournamentLeaderboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TournamentResource::class;

    protected string $view = 'filament.pages.tournaments.tournament-leaderboard';

    public ?Tournament $record = null;

    public function mount(Tournament $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string
    {
        return 'Tournament Leaderboard - ' . $this->record->name;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                $this->record
                    ->qualificationAttempts()
                    ->with('user')
                    ->getQuery()
                    ->orderByDesc('score')
                    ->orderBy('created_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Participant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('score')
                    ->label('Qualification Score')
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono font-bold']),

                Tables\Columns\TextColumn::make('duration_seconds')
                    ->label('Time Taken')
                    ->formatStateUsing(fn ($state) => $state ? sprintf('%02d:%02d', intval($state / 60), intval($state % 60)) : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Attempted At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'passed' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('status')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'passed' => 'Passed',
                                'failed' => 'Failed',
                                'pending' => 'Pending',
                            ])
                            ->placeholder('Any'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when($data['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s));
                    }),

                Tables\Filters\Filter::make('score_range')
                    ->form([
                        Forms\Components\TextInput::make('min_score')->label('Min score')->numeric(),
                        Forms\Components\TextInput::make('max_score')->label('Max score')->numeric(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(isset($data['min_score']) && $data['min_score'] !== null, fn (Builder $q, $v) => $q->where('score', '>=', $v))
                            ->when(isset($data['max_score']) && $data['max_score'] !== null, fn (Builder $q, $v) => $q->where('score', '<=', $v));
                    }),
            ])
            ->defaultSort('score', 'desc');
    }
}
