<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use App\Models\Level;
use App\Models\Grade;
use App\Models\User;

class Dashboard extends BaseDashboard
{
    // Filament v4 expects this exact union type
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?int $navigationSort = -2;

    use HasFiltersForm;

    // Provide dashboard-wide filters (start and end date)
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    DatePicker::make('startDate')->label('Start date'),
                    DatePicker::make('endDate')->label('End date'),
                    Select::make('level')->label('Level')->options(function () {
                        return Level::orderBy('order')->pluck('name', 'id')->toArray();
                    })->searchable()->nullable(),
                    Select::make('grade')->label('Grade/Course')->options(function () {
                        return Grade::orderBy('name')->pluck('display_name', 'id')->toArray();
                    })->searchable()->nullable(),
                    Select::make('creator')->label('Quiz creator')->options(function () {
                        // Only include users who have authored quizzes to keep the list small and relevant
                        return User::whereHas('quizzes')->orderBy('name')->pluck('name', 'id')->toArray();
                    })->searchable()->nullable(),
                ])
                ->columns(3),
        ]);
    }

    // Use our custom widgets only (prevents default Filament cards showing)
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\AdminStatsOverview::class,
            \App\Filament\Widgets\QuizzesTrend::class,
            \App\Filament\Widgets\TopSubjectsPie::class,
            // additional widgets requested
            \App\Filament\Widgets\TopStudentsTable::class,
            \App\Filament\Widgets\TopQuizMastersTable::class,
            \App\Filament\Widgets\RegistrationsQuizzesScoresChart::class,
            \App\Filament\Widgets\LatestQuizzesTaken::class,
            \App\Filament\Widgets\RecentTransactions::class,
        ];
    }

    // Override columns for nicer layout
    public function getColumns(): int | array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 4,
        ];
    }
}
