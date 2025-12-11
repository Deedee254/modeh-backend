<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TournamentResource\Pages;
use App\Models\Tournament;
use App\Models\Question;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
class TournamentResource extends Resource
{
    protected static ?string $model = Tournament::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';
    protected static \UnitEnum|string|null $navigationGroup = 'Tournaments';
    protected static ?int $navigationSort = 1;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\RichEditor::make('description')
                        ->required()
                        ->maxLength(65535)
                        ->columnSpan('full')
                        ->extraAttributes(['style' => 'min-height: 18rem;']),
                ])
                ->columns(2),

            Section::make('Sponsor Information')
                ->description('Configure sponsor details and branding')
                ->schema([
                    Forms\Components\Select::make('sponsor_id')
                        ->label('Sponsor')
                        ->relationship('sponsor', 'name')
                        ->preload()
                        ->searchable(),

                    Forms\Components\FileUpload::make('sponsor_banner')
                        ->label('Sponsor Banner')
                        ->image()
                        ->disk('public')
                        ->directory('sponsor-banners')
                        ->visibility('public')
                        ->maxSize(5120) // 5MB
                        ->visible(fn ($get): bool => filled($get('sponsor_id'))),

                    Forms\Components\Textarea::make('sponsor_message')
                        ->label('Sponsor Message')
                        ->maxLength(65535)
                        ->visible(fn ($get): bool => filled($get('sponsor_id'))),
                ])
                ->columns(1),

            Section::make()
                ->schema([
                    // Left column: Taxonomy
                    Grid::make(1)->schema([
                        Forms\Components\Select::make('level_id')
                            ->relationship('level', 'name', fn (Builder $query) => $query->orderBy('order'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($set) {
                                $set('grade_id', null);
                                $set('subject_id', null);
                                $set('topic_id', null);
                            }),

                        Forms\Components\Select::make('grade_id')
                            ->relationship('grade', 'display_name', function (Builder $query, $get) {
                                $levelId = $get('level_id');
                                if ($levelId) {
                                    $query->where('level_id', $levelId);
                                }
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($set) {
                                $set('subject_id', null);
                                $set('topic_id', null);
                            }),

                        Forms\Components\Select::make('subject_id')
                            ->relationship('subject', 'name', function (Builder $query, $get) {
                                $gradeId = $get('grade_id');
                                if ($gradeId) {
                                    $query->where('grade_id', $gradeId);
                                }
                            })
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function ($set) {
                                $set('topic_id', null);
                            }),

                        Forms\Components\Select::make('topic_id')
                            ->relationship('topic', 'name', function (Builder $query, $get) {
                                $subjectId = $get('subject_id');
                                if ($subjectId) {
                                    $query->where('subject_id', $subjectId);
                                }
                            })
                            ->searchable()
                            ->preload()
                            ->live(),

                        Action::make('browseBank')
                            ->label('Add from Bank')
                            ->icon('heroicon-o-archive-box')
                            ->modalHeading('Browse Question Bank')
                            ->modalWidth('7xl')
                            ->modalContent(function ($get) {
                                return view('filament.modals.bank-questions-table', [
                                    'filters' => [
                                        'level_id' => $get('level_id'),
                                        'grade_id' => $get('grade_id'),
                                        'subject_id' => $get('subject_id'),
                                        'topic_id' => $get('topic_id'),
                                    ]
                                ]);
                            })
                            ->modalSubmitAction(false)
                            ->modalCancelAction(false)
                            ->color('secondary'),
                    ]),
                ]),

            Section::make('Additional Settings')
                ->schema([
                    Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_date')
                                ->label('Start Date')
                                ->required(),

                            Forms\Components\DateTimePicker::make('end_date')
                                ->label('End Date')
                                ->required(),

                            Forms\Components\TextInput::make('prize_pool')
                                ->label('Prize Pool (Ksh)')
                                ->numeric()
                                ->prefix('Ksh')
                                ->required(),

                            Forms\Components\TextInput::make('entry_fee')
                                ->label('Entry Fee (Ksh)')
                                ->numeric()
                                ->prefix('Ksh')
                                ->default(0),

                            Forms\Components\TextInput::make('max_participants')
                                ->label('Max Participants')
                                ->numeric()
                                ->minValue(2)
                                ->required(),
                        ]),

                    Forms\Components\TagsInput::make('rules')
                        ->placeholder('Add a rule')
                        ->splitKeys(['Enter', 'Tab', ','])
                        ->helperText('Press Enter or Tab to add a rule'),
                ])
                ->columns(1),

            Section::make('Tournament Configuration')
                ->description('Configure timing, question counts, and bracket settings')
                ->columnSpan('full')
                ->schema([
                    Grid::make(2)->schema([
                        Grid::make(1)->schema([
                            Forms\Components\TextInput::make('qualifier_per_question_seconds')
                                ->label('Qualifier: Seconds per Question')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(300)
                                ->default(30)
                                ->helperText('Time allowed per question in qualifier phase (5-300 seconds)'),

                            Forms\Components\TextInput::make('qualifier_question_count')
                                ->label('Qualifier: Number of Questions')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(10)
                                ->helperText('How many questions in qualifier phase (1-100)'),

                            Forms\Components\Select::make('qualifier_tie_breaker')
                                ->label('Qualifier: Tie-Breaker Rule')
                                ->options([
                                    'score_then_duration' => 'Score (higher) then Speed (faster)',
                                    'duration' => 'Speed (faster only)',
                                ])
                                ->default('score_then_duration')
                                ->helperText('How to rank participants with same scores'),

                            Forms\Components\TextInput::make('qualifier_days')
                                ->label('Qualifier Duration (Days)')
                                ->numeric()
                                ->minValue(1)
                                ->default(7)
                                ->helperText('Number of days the qualifier phase runs before battles are generated'),
                        ]),

                        Grid::make(1)->schema([
                            Forms\Components\TextInput::make('battle_per_question_seconds')
                                ->label('Battle: Seconds per Question')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(300)
                                ->default(30)
                                ->helperText('Time allowed per question in battle phase (5-300 seconds)'),

                            Forms\Components\TextInput::make('battle_question_count')
                                ->label('Battle: Number of Questions')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(10)
                                ->helperText('How many questions per battle (1-100)'),

                            Forms\Components\Select::make('bracket_slots')
                                ->label('Tournament Bracket Size')
                                ->options([
                                    2 => '2 Players (1 Battle)',
                                    4 => '4 Players (2 Semifinals → 1 Final)',
                                    8 => '8 Players (4 Quarters → 2 Semis → 1 Final)',
                                ])
                                ->default(8)
                                ->helperText('Top N from qualifier will advance to bracket'),

                            Forms\Components\TextInput::make('round_delay_days')
                                ->label('Days Between Rounds')
                                ->numeric()
                                ->minValue(1)
                                ->default(3)
                                ->helperText('Number of days between closing one round and generating the next'),
                        ]),
                    ]),
                ])
                ->columns(1)
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Tournament $record) => static::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(false),

                \Filament\Tables\Columns\TextColumn::make('sponsor.name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'upcoming' => 'warning',
                        'active' => 'success',
                        'completed' => 'gray',
                        default => 'gray',
                    }),

                \Filament\Tables\Columns\TextColumn::make('prize_pool')
                    ->money()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('participants_count')
                    ->counts('participants')
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('grade.name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('subject.name')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'active' => 'Active',
                        'completed' => 'Completed',
                    ]),

                \Filament\Tables\Filters\SelectFilter::make('grade')
                    ->relationship('grade', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('subject')
                    ->relationship('subject', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('sponsor')
                    ->relationship('sponsor', 'name'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make()
                    ->visible(fn (Tournament $record): bool => $record->status === 'upcoming'),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager::class,
            \App\Filament\Resources\TournamentResource\RelationManagers\QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTournaments::route('/'),
            'create' => Pages\CreateTournament::route('/create'),
            'leaderboard' => Pages\Leaderboard::route('/{record}/leaderboard'),
            'qualifier_leaderboard' => Pages\QualifierLeaderboard::route('/{record}/qualifier-leaderboard'),
            'edit' => Pages\EditTournament::route('/{record}/edit'),
            'view' => Pages\ViewTournament::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'active')->count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('participants');
    }
}