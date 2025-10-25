<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Tables\Columns\TextColumn;
use App\Models\User;

class TopQuizMastersTable extends TableWidget
{
    protected static ?int $sort = 13;

    use InteractsWithPageFilters;

    protected function getTableQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $q = User::query()->whereHas('quizzes')->withCount('quizzes');

        $this->columnSpan = 1;

        // Optionally apply page filters that are quiz-scoped (level/grade/creator)
        if (!empty($this->pageFilters['level']) || !empty($this->pageFilters['grade'])) {
            $level = $this->pageFilters['level'] ?? null;
            $grade = $this->pageFilters['grade'] ?? null;
            $q->whereHas('quizzes', function ($qq) use ($level, $grade) {
                if ($level) $qq->where('level_id', $level);
                if ($grade) $qq->where('grade_id', $grade);
            });
        }

        return $q->orderByDesc('quizzes_count');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Quiz Master')->searchable()->sortable(),
            TextColumn::make('quizzes_count')->label('Quizzes')->sortable(),
        ];
    }

    protected function getDefaultTableRecordsPerPage(): ?int
    {
        return 10;
    }
}
