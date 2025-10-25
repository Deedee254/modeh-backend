<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Tables\Columns\TextColumn;
use App\Models\User;

class TopStudentsTable extends TableWidget
{
    protected static ?int $sort = 12;

    use InteractsWithPageFilters;

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = User::query()->withCount('quizAttempts')->withAvg('quizAttempts', 'score')->whereHas('quizAttempts');
        $this->columnSpan = 1;

        // apply date filters to the quizAttempts aggregate via a subquery constraint
        if (!empty($this->pageFilters['startDate']) || !empty($this->pageFilters['endDate'])) {
            $start = $this->pageFilters['startDate'] ?? null;
            $end = $this->pageFilters['endDate'] ?? null;
            $q->whereHas('quizAttempts', function ($qa) use ($start, $end) {
                if ($start) $qa->whereDate('created_at', '>=', $start);
                if ($end) $qa->whereDate('created_at', '<=', $end);
            });
        }

        // apply creator/level/grade filters by constraining the quizAttempts' quiz
        if (!empty($this->pageFilters['level']) || !empty($this->pageFilters['grade']) || !empty($this->pageFilters['creator'])) {
            $level = $this->pageFilters['level'] ?? null;
            $grade = $this->pageFilters['grade'] ?? null;
            $creator = $this->pageFilters['creator'] ?? null;
            $q->whereHas('quizAttempts.quiz', function ($qq) use ($level, $grade, $creator) {
                if ($level) $qq->where('level_id', $level);
                if ($grade) $qq->where('grade_id', $grade);
                if ($creator) $qq->where('user_id', $creator);
            });
        }

        return $q->orderByDesc('quiz_attempts_avg_score');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Student')->searchable()->sortable(),
            TextColumn::make('quiz_attempts_count')->label('Attempts')->sortable(),
            TextColumn::make('quiz_attempts_avg_score')->label('Avg Score')->formatStateUsing(fn($state) => $state ? number_format($state,2) : '0.00')->sortable(),
        ];
    }

    protected function getDefaultTableRecordsPerPage(): ?int
    {
        return 10;
    }
}
