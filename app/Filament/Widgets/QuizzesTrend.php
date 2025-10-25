<?php

namespace App\Filament\Widgets;

use Filament\Widgets\BarChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class QuizzesTrend extends BarChartWidget
{
    protected static ?int $sort = 5;
    protected int | string | array $columnSpan = 1;

    use InteractsWithPageFilters;

    protected function getData(): array
    {
        // Use the dashboard filters; fall back to last 14 days
        $start = !empty($this->pageFilters['startDate']) ? Carbon::parse($this->pageFilters['startDate'])->startOfDay() : now()->copy()->subDays(13)->startOfDay();
        $end = !empty($this->pageFilters['endDate']) ? Carbon::parse($this->pageFilters['endDate'])->endOfDay() : now()->endOfDay();

        $level = $this->pageFilters['level'] ?? null;
        $grade = $this->pageFilters['grade'] ?? null;
        $creator = $this->pageFilters['creator'] ?? null;

        $cacheKey = 'dashboard:quizzes_trend:' . $start->toDateString() . ':' . $end->toDateString() . ':' . ($level ?? 'n') . ':' . ($grade ?? 'n') . ':' . ($creator ?? 'n');

        if (Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
            $result = Cache::tags(['dashboard_charts'])->remember($cacheKey, 60, function () use ($start, $end, $level, $grade, $creator) {
                $rows = DB::table('quizzes')
                    ->select(DB::raw("DATE(created_at) as day"), DB::raw('COUNT(*) as cnt'))
                    ->whereBetween('created_at', [$start, $end]);

                if ($level) $rows->where('level_id', $level);
                if ($grade) $rows->where('grade_id', $grade);
                if ($creator) $rows->where('user_id', $creator);

                $rows = $rows->groupBy('day')->orderBy('day')->pluck('cnt', 'day')->toArray();

                return $rows;
            });
        } else {
            $result = Cache::remember($cacheKey, 60, function () use ($start, $end, $level, $grade, $creator) {
                $rows = DB::table('quizzes')
                    ->select(DB::raw("DATE(created_at) as day"), DB::raw('COUNT(*) as cnt'))
                    ->whereBetween('created_at', [$start, $end]);

                if ($level) $rows->where('level_id', $level);
                if ($grade) $rows->where('grade_id', $grade);
                if ($creator) $rows->where('user_id', $creator);

                $rows = $rows->groupBy('day')->orderBy('day')->pluck('cnt', 'day')->toArray();

                return $rows;
            });
        }

        // Build full day labels between start and end
        $labels = [];
        $data = [];
        $period = new \DatePeriod(new \DateTime($start->toDateString()), new \DateInterval('P1D'), (new \DateTime($end->toDateString()))->modify('+1 day'));
        foreach ($period as $dt) {
            $day = $dt->format('Y-m-d');
            $labels[] = Carbon::parse($day)->format('M j');
            $data[] = (int)($result[$day] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Quizzes created',
                    'data' => $data,
                    'backgroundColor' => '#acc236',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
