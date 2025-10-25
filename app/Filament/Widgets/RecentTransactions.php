<?php

namespace App\Filament\Widgets;

use Filament\Widgets\TableWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Models\Transaction;
use App\Models\Quiz;

class RecentTransactions extends TableWidget
{
    protected static ?int $sort = 20;

    use InteractsWithPageFilters;

    protected function getTableQuery(): Builder|Relation|null
    {
        $q = Transaction::query()->with('user');
        if (!empty($this->pageFilters['startDate'])) {
            $q->whereDate('created_at', '>=', $this->pageFilters['startDate']);
        }
        if (!empty($this->pageFilters['endDate'])) {
            $q->whereDate('created_at', '<=', $this->pageFilters['endDate']);
        }

        // Apply level/grade/creator filters by limiting to quiz ids that match
        $level = $this->pageFilters['level'] ?? null;
        $grade = $this->pageFilters['grade'] ?? null;
        $creator = $this->pageFilters['creator'] ?? null;

        if ($level || $grade || $creator) {
            $quizQ = Quiz::query();
            if ($level) $quizQ->where('level_id', $level);
            if ($grade) $quizQ->where('grade_id', $grade);
            if ($creator) $quizQ->where('user_id', $creator);
            // Respect date filters as well for consistency
            if (!empty($this->pageFilters['startDate'])) {
                $quizQ->whereDate('created_at', '>=', $this->pageFilters['startDate']);
            }
            if (!empty($this->pageFilters['endDate'])) {
                $quizQ->whereDate('created_at', '<=', $this->pageFilters['endDate']);
            }
            $quizIds = $quizQ->pluck('id')->toArray();
            if (count($quizIds) > 0) {
                $q->whereIn('quiz_id', $quizIds);
            } else {
                $q->whereRaw('1 = 0');
            }
        }
        return $q->orderByDesc('created_at');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('tx_id')->label('Tx')->limit(20),
            TextColumn::make('user.name')->label('User')->toggleable(),
            TextColumn::make('amount')->label('Amount')->formatStateUsing(fn($state) => number_format($state,2)),
            TextColumn::make('gateway')->label('Gateway')->toggleable(),
            TextColumn::make('status')->label('Status')->toggleable(),
            TextColumn::make('created_at')->label('When')->dateTime()->sortable()->toggleable(),
        ];
    }

    protected function getDefaultTableRecordsPerPage(): ?int
    {
        return 10;
    }
}
