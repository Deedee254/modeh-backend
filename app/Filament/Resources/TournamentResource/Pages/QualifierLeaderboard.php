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

class QualifierLeaderboard extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TournamentResource::class;

    protected string $view = 'filament.pages.tournaments.qualifier-leaderboard';

    public ?Tournament $record = null;

    /**
     * Array of user IDs who will qualify (top N according to bracket_slots)
     * Computed at mount time so table row rendering can highlight them.
     * @var array<int>
     */
    protected array $qualifierIds = [];

    public function mount(Tournament $record): void
    {
        $this->record = $record;

        // Pre-compute top qualifiers (unique users) based on existing qualification attempts
        try {
            $slots = (int) ($this->record->bracket_slots ?? 8);
            $attempts = \App\Models\TournamentQualificationAttempt::where('tournament_id', $this->record->id)
                ->orderByDesc('score')
                ->orderBy('duration_seconds')
                ->get();

            if ($attempts->isNotEmpty()) {
                $selected = $attempts->groupBy('user_id')->map(function($g) { return $g->first(); })->values();
                $selected = $selected->take($slots);
                $this->qualifierIds = $selected->pluck('user_id')->toArray();
            }
        } catch (\Throwable $_) {
            // non-fatal: leave qualifierIds empty and fallback to no highlighting
            $this->qualifierIds = [];
        }
    }

    public function table(Table $table): Table
    {
        return $table
            // Highlight qualified rows green and non-qualified rows red
            ->rowClasses(fn ($record) => in_array($record->user_id, $this->qualifierIds) ? 'bg-emerald-50' : 'bg-rose-50')
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
                Tables\Columns\BadgeColumn::make('is_qualified')
                    ->label('Qualified')
                    ->getStateUsing(fn ($record) => in_array($record->user_id, $this->qualifierIds) ? 'Yes' : 'No')
                    ->color(fn ($state) => $state === 'Yes' ? 'success' : 'danger')
                    ->sortable(false),
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
