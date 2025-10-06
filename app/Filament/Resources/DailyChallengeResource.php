<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyChallengeResource\Pages;
use App\Models\DailyChallenge;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;
use Filament\Tables\Columns\BooleanColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\Filter;

class DailyChallengeResource extends Resource
{
    protected static ?string $model = DailyChallenge::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Textarea::make('description'),
            Select::make('difficulty')
                ->options([
                    'easy' => 'Easy',
                    'medium' => 'Medium',
                    'hard' => 'Hard',
                ])
                ->required(),
            Select::make('grade_id')
                ->relationship('grade', 'name')
                ->required(),
            Select::make('subject_id')
                ->relationship('subject', 'name')
                ->required(),
            TextInput::make('points_reward')->numeric()->default(0),
            DatePicker::make('date')->required(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('difficulty')->badge()->searchable()->sortable(),
                TextColumn::make('grade.name')->label('Grade')->searchable()->sortable(),
                TextColumn::make('subject.name')->label('Subject')->searchable()->sortable(),
                TextColumn::make('points_reward')->label('Points')->numeric()->sortable(),
                TextColumn::make('date')->date()->sortable(),
                BooleanColumn::make('is_active')->label('Active'),
            ])
            ->filters([
                SelectFilter::make('difficulty')
                    ->options([
                        'easy' => 'Easy',
                        'medium' => 'Medium',
                        'hard' => 'Hard',
                    ])
                    ->label('Difficulty'),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) $indicators[] = 'From '.$data['from'];
                        if ($data['until'] ?? null) $indicators[] = 'Until '.$data['until'];
                        return $indicators;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyChallenges::route('/'),
            'create' => Pages\CreateDailyChallenge::route('/create'),
            'edit' => Pages\EditDailyChallenge::route('/{record}/edit'),
        ];
    }
}