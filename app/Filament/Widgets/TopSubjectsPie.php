<?php

namespace App\Filament\Widgets;

use Filament\Widgets\PieChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\Subject;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

class TopSubjectsPie extends PieChartWidget
{
    protected static ?int $sort = 10;
    protected int|string|array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'lg' => 1,
        'xl' => 1,
    ];

    use InteractsWithPageFilters;

    protected function getData(): array
    {
        // Get top subjects by quiz count
        $query = DB::table('quizzes')
            ->select('subject_id', DB::raw('count(*) as cnt'))
            ->whereNotNull('subject_id')
        ;

        if (!empty($this->pageFilters['startDate'])) {
            $query->whereDate('created_at', '>=', $this->pageFilters['startDate']);
        }
        if (!empty($this->pageFilters['endDate'])) {
            $query->whereDate('created_at', '<=', $this->pageFilters['endDate']);
        }

        if (!empty($this->pageFilters['level'])) {
            $query->where('level_id', $this->pageFilters['level']);
        }
        if (!empty($this->pageFilters['grade'])) {
            $query->where('grade_id', $this->pageFilters['grade']);
        }
        if (!empty($this->pageFilters['creator'])) {
            $query->where('user_id', $this->pageFilters['creator']);
        }

        $rows = $query
            ->groupBy('subject_id')
            ->orderByDesc('cnt')
            ->limit(6)
            ->get();

        $labels = [];
        $data = [];
        $palette = [
            '#537bc4', // Blue
            '#891f21', // Burgundy
            '#F9B82E', // Yellow
            '#acc236', // Green
            '#166a8f', // Dark Blue
            '#00a950', // Emerald
        ];
        $colors = [];
        foreach ($rows as $r) {
            $subject = Subject::find($r->subject_id);
            $labels[] = $subject ? $subject->name : "#{$r->subject_id}";
            $data[] = (int) $r->cnt;
            $colors[] = $palette[count($colors) % count($palette)];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Quizzes by subject',
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
