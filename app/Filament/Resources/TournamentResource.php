<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TournamentResource\Pages;
use App\Models\Tournament;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        ->maxLength(65535),

                    Forms\Components\Select::make('sponsor_id')
                        ->relationship('sponsor', 'name')
                        ->preload()
                        ->searchable(),

                    Forms\Components\TextInput::make('sponsor_banner_url')
                        ->url()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => filled($get('sponsor_id'))),

                    Forms\Components\Textarea::make('sponsor_message')
                        ->maxLength(65535)
                        ->visible(fn (Get $get): bool => filled($get('sponsor_id'))),

                    Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_date')
                                ->required(),

                            Forms\Components\DateTimePicker::make('end_date')
                                ->required(),

                            Forms\Components\TextInput::make('prize_pool')
                                ->numeric()
                                ->prefix('$')
                                ->required(),

                            Forms\Components\TextInput::make('entry_fee')
                                ->numeric()
                                ->prefix('$')
                                ->default(0),

                            Forms\Components\TextInput::make('max_participants')
                                ->numeric()
                                ->minValue(2)
                                ->required(),
                        ]),
                ])
                ->columns(2),

            Section::make()
                ->schema([
                    Forms\Components\Select::make('grade_id')
                        ->relationship('grade', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),

                    Forms\Components\Select::make('subject_id')
                        ->relationship('subject', 'name')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live(),

                    Forms\Components\Select::make('topic_id')
                        ->relationship('topic', 'name', function (Builder $query, Get $get): void {
                            $query->where('subject_id', $get('subject_id'));
                        })
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get): bool => filled($get('subject_id'))),

                    Forms\Components\Select::make('questions')
                        ->multiple()
                        ->relationship('questions', 'content', function (Builder $query, Get $get): void {
                            $query
                                ->when($get('grade_id'), fn (Builder $innerQuery) => $innerQuery->where('grade_id', $get('grade_id')))
                                ->when($get('subject_id'), fn (Builder $innerQuery) => $innerQuery->where('subject_id', $get('subject_id')))
                                ->when($get('topic_id'), fn (Builder $innerQuery) => $innerQuery->where('topic_id', $get('topic_id')));
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->visible(fn (Get $get): bool => filled($get('subject_id')))
                        ->live(),

                    Forms\Components\Placeholder::make('total_questions')
                        ->label('Selected Questions')
                        ->content(fn (Get $get): string => sprintf('%d questions', count($get('questions') ?? []))),

                    Forms\Components\TagsInput::make('rules')
                        ->placeholder('Add a rule')
                        ->splitKeys(['Enter', 'Tab', ','])
                        ->helperText('Press Enter or Tab to add a rule'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

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
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('generate_matches')
                    ->action(fn (Tournament $record) => $record->generateMatches())
                    ->requiresConfirmation()
                    ->visible(fn (Tournament $record): bool => $record->status === 'upcoming')
                    ->color('success')
                    ->icon('heroicon-o-play'),
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
            'index' => Pages\ListTournaments::route('/'),
            'create' => Pages\CreateTournament::route('/create'),
            'edit' => Pages\EditTournament::route('/{record}/edit'),
            'view' => Pages\ViewTournament::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'active')->count();
    }
}