<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Tables\Columns\TextColumn;
use App\Models\QuizAttempt;
use Illuminate\Support\Facades\DB;

class LatestQuizzesTaken extends TableWidget
{
    protected static ?int $sort = 15;

    protected int | string | array $columnSpan = 2;

    use InteractsWithPageFilters;

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Use a single joined query so the SQL can apply filters efficiently
        $q = QuizAttempt::query()->select('quiz_attempts.*')
            ->with(['user', 'quiz'])
            ->join('quizzes', 'quiz_attempts.quiz_id', '=', 'quizzes.id');

        if (!empty($this->pageFilters['startDate'])) {
            $q->whereDate('quiz_attempts.created_at', '>=', $this->pageFilters['startDate']);
        }
        if (!empty($this->pageFilters['endDate'])) {
            $q->whereDate('quiz_attempts.created_at', '<=', $this->pageFilters['endDate']);
        }

        if (!empty($this->pageFilters['level'])) {
            $q->where('quizzes.level_id', $this->pageFilters['level']);
        }
        if (!empty($this->pageFilters['grade'])) {
            $q->where('quizzes.grade_id', $this->pageFilters['grade']);
        }
        if (!empty($this->pageFilters['creator'])) {
            $q->where('quizzes.user_id', $this->pageFilters['creator']);
        }

        return $q->orderByDesc('quiz_attempts.created_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('user.name')->label('User')->toggleable()->sortable(),
            TextColumn::make('quiz.title')->label('Quiz')->toggleable()->sortable(),
            TextColumn::make('score')->label('Score')->formatStateUsing(fn($s) => number_format($s,2))->sortable(),
            TextColumn::make('created_at')->label('Taken At')->dateTime()->sortable(),
        ];
    }

    protected function getDefaultTableRecordsPerPage(): ?int
    {
        return 10;
    }
}
