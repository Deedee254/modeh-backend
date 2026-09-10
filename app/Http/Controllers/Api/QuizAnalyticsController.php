<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizAnalyticsController extends Controller
{
    // Require authentication (quiz owner or admin)
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Return analytics summary for a quiz.
     *
     * Response shape:
     * {
     *   attempts_count: int,
     *   completions: int,
     *   avg_score: float,
     *   avg_time_seconds: float,
     *   per_question: [{ question_id, correct_count, attempts_count, correct_rate }]
     * }
     */
    public function show(Request $request, Quiz $quiz)
    {
        $this->authorize('viewAnalytics', $quiz);

        $quiz->load('questions');
        $attempts = QuizAttempt::query()
            ->where('quiz_id', $quiz->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();

        $attemptsCount = $attempts->count();
        $completions = $attempts->filter(fn ($attempt) => !is_null($attempt->score))->count();
        $attemptsWithScores = $attempts->filter(fn ($attempt) => $attempt->score !== null);
        $avgScore = round($attemptsWithScores->avg('score') ?? 0, 2);
        $avgTime = round($attempts->avg('total_time_seconds') ?? 0, 2);

        $medianSeconds = null;
        if ($attempts->filter(fn ($attempt) => $attempt->total_time_seconds !== null)->isNotEmpty()) {
            $seconds = $attempts->pluck('total_time_seconds')->filter(fn ($value) => $value !== null)->sort()->values()->all();
            $count = count($seconds);
            if ($count > 0) {
                $mid = intdiv($count, 2);
                $medianSeconds = $count % 2 === 0
                    ? (($seconds[$mid - 1] + $seconds[$mid]) / 2)
                    : $seconds[$mid];
            }
        }

        $scoreDistribution = array_fill(0, 11, 0);
        foreach ($attemptsWithScores as $attempt) {
            $bucket = min(10, (int) floor((float) ($attempt->score ?? 0) / 10));
            $scoreDistribution[$bucket]++;
        }

        $trendStart = now()->subDays(29)->startOfDay();
        $trendRows = DB::table('quiz_attempts')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as cnt'))
            ->where('quiz_id', $quiz->id)
            ->where('created_at', '>=', $trendStart)
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $trendMap = $trendRows->mapWithKeys(fn ($row) => [(string) $row->day => (int) $row->cnt]);
        $attemptsTrend = [];
        for ($i = 0; $i < 30; $i++) {
            $day = $trendStart->copy()->addDays($i)->format('Y-m-d');
            $attemptsTrend[] = ['date' => $day, 'value' => (int) ($trendMap[$day] ?? 0)];
        }

        $perQuestionStats = [];
        foreach ($quiz->questions as $question) {
            $questionAttempts = 0;
            $correctCount = 0;

            foreach ($attempts as $attempt) {
                $answers = is_array($attempt->answers) ? $attempt->answers : json_decode((string) $attempt->answers, true) ?? [];
                foreach ($answers as $ans) {
                    if (($ans['question_id'] ?? null) != $question->id) {
                        continue;
                    }

                    $questionAttempts++;
                    $selected = $ans['selected'] ?? null;
                    $correctAnswers = is_array($question->answers) ? $question->answers : json_decode((string) $question->answers, true) ?? [];
                    $isCorrect = false;

                    if (is_array($selected)) {
                        $isCorrect = $selected == $correctAnswers;
                    } else {
                        $isCorrect = in_array((string) $selected, array_map('strval', (array) $correctAnswers), true);
                    }

                    if ($isCorrect) {
                        $correctCount++;
                    }
                }
            }

            $accuracy = $questionAttempts ? round(($correctCount / $questionAttempts) * 100, 1) : null;
            $perQuestionStats[] = [
                'id' => $question->id,
                'label' => $question->body ?: $question->question ?: 'Question',
                'body' => $question->body ?: $question->question ?: 'Question',
                'question_id' => $question->id,
                'difficulty' => $question->difficulty ?? '—',
                'accuracy' => $accuracy,
                'avg_score' => $accuracy,
                'attempts_count' => $questionAttempts,
                'correct_count' => $correctCount,
            ];
        }

        $completion = [
            'pass_rate' => $attemptsCount ? round(($completions / $attemptsCount) * 100, 1) : 0,
            'abandon_rate' => $attemptsCount ? round(((int) $attemptsCount - (int) $completions) / max(1, (int) $attemptsCount) * 100, 1) : 0,
            'finishers' => $completions,
            'abandon_points' => [],
        ];

        $recentAttempts = $attempts->take(8)->map(fn ($attempt) => [
            'id' => $attempt->id,
            'user_name' => $attempt->user?->name ?? 'Anonymous',
            'user' => $attempt->user?->name ?? 'Anonymous',
            'attempted_at' => $attempt->created_at?->toIso8601String(),
            'score' => $attempt->score,
        ])->values()->all();

        $stats = [
            'totalAttempts' => $attemptsCount,
            'totalAttemptsTrend' => null,
            'completionRate' => $attemptsCount ? round(($completions / $attemptsCount) * 100, 1) : 0,
            'completionRateTrend' => null,
            'averageScore' => $attemptsWithScores->count() ? round($avgScore, 1) : 0,
            'averageScoreTrend' => null,
            'medianCompletionSeconds' => $medianSeconds,
        ];

        // Keep the legacy analytics keys present so older consumers stay compatible.
        $legacyPerQuestion = [];
        foreach ($perQuestionStats as $item) {
            $legacyPerQuestion[] = [
                'question_id' => $item['question_id'],
                'body' => $item['body'],
                'attempts_count' => $item['attempts_count'],
                'correct_count' => $item['correct_count'],
                'correct_rate' => $item['attempts_count'] ? round(($item['correct_count'] / $item['attempts_count']), 3) : null,
            ];
        }

        $topMissedQuestions = array_values(array_slice($legacyPerQuestion, 0, 5));

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title ?? $quiz->name,
                'name' => $quiz->title ?? $quiz->name,
            ],
            'stats' => $stats,
            'series' => $attemptsTrend,
            'completion' => $completion,
            'questions' => $perQuestionStats,
            'segments' => [],
            'recent_attempts' => $recentAttempts,
            'attempts_count' => $attemptsCount,
            'completions' => $completions,
            'avg_score' => $avgScore,
            'avg_time_seconds' => $avgTime,
            'per_question' => $legacyPerQuestion,
            'score_distribution' => $scoreDistribution,
            'attempts_trend' => $attemptsTrend,
            'top_missed_questions' => $topMissedQuestions,
        ]);
    }

    // Server-side CSV export
    public function exportCsv(Request $request, Quiz $quiz)
    {
        $this->authorize('viewAnalytics', $quiz);
        $filename = "quiz-{$quiz->id}-analytics.csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}"
        ];

        $rows = [];
        $rows[] = ['question_id','question','attempts','correct','correct_rate'];
        foreach ($quiz->questions as $q) {
            // compute stats on the fly (reuse logic from show)
            $attempts = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('answers')->get();
            $attemptCount = 0; $correctCount = 0;
            foreach ($attempts as $a) {
                $answers = $a->answers ?? [];
                foreach ($answers as $ans) {
                    $qid = intval($ans['question_id'] ?? 0);
                    if ($qid !== $q->id) continue;
                    $attemptCount++;
                    $selected = $ans['selected'] ?? null;
                    $correctAnswers = is_array($q->answers) ? $q->answers : json_decode((string) $q->answers, true) ?? [];
                    // Build option map for this question
                    $optionMap = [];
                    if (is_array($q->options)) {
                        foreach ($q->options as $idx => $opt) {
                            if (is_array($opt)) {
                                if (isset($opt['id'])) {
                                    $optionMap[(string)$opt['id']] = $opt['text'] ?? $opt['body'] ?? null;
                                }
                                if (isset($opt['text']) || isset($opt['body'])) {
                                    $optionMap[(string)$idx] = $opt['text'] ?? $opt['body'] ?? null;
                                }
                            }
                        }
                    }

                    $normalizeForCompare = function($val) use ($optionMap) {
                        if (is_array($val) && (isset($val['body']) || isset($val['text']))) {
                            $text = $val['text'] ?? $val['body'] ?? '';
                        } else {
                            $key = (string)$val;
                            if ($key !== '' && isset($optionMap[$key])) {
                                $text = $optionMap[$key];
                            } else {
                                $text = (string)$val;
                            }
                        }
                        return strtolower(trim((string)$text));
                    };

                    $normalizeArray = function($arr) use ($normalizeForCompare) {
                        $normalized = array_map($normalizeForCompare, $arr ?: []);
                        $normalized = array_filter($normalized, function ($v) { return $v !== null && $v !== ''; });
                        sort($normalized);
                        return array_values($normalized);
                    };

                    $isCorrect = false;
                    if (is_array($selected)) {
                        $normSubmitted = $normalizeArray($selected);
                        $normCorrect = $normalizeArray($correctAnswers);
                        $isCorrect = $normSubmitted == $normCorrect;
                    } else {
                        $submitted = $normalizeForCompare($selected);
                        $normCorrect = $normalizeArray($correctAnswers);
                        $isCorrect = in_array($submitted, $normCorrect);
                    }
                    if ($isCorrect) $correctCount++;
                }
            }
            $rate = $attemptCount ? round($correctCount / $attemptCount, 3) : '';
            $rows[] = [$q->id, $q->body, $attemptCount, $correctCount, $rate];
        }

        $callback = function() use ($rows) {
            $FH = fopen('php://output', 'w');
            foreach ($rows as $r) {
                fputcsv($FH, $r);
            }
            fclose($FH);
        };

        return response()->stream($callback, 200, $headers);
    }

    // Server-side PDF export using DOMPDF
    public function exportPdf(Request $request, Quiz $quiz)
    {
        $this->authorize('viewAnalytics', $quiz);

        // Reuse the existing show() to gather analytics data
        $analyticsResponse = $this->show($request, $quiz)->getData(true);

        // Resolve a logo from several likely locations (backend public, frontend public)
        // Prefer explicit backend public logo at /public/modeh-logo.png, fall back to other candidates
        $candidates = [
            public_path('modeh-logo.png'),
            public_path('logo/modeh-logo.png'),
            public_path('images/modeh-logo.png'),
            public_path('images/logo.png'),
            public_path('logo.png'),
            // sibling frontend public folder (common monorepo layout)
            dirname(base_path()).DIRECTORY_SEPARATOR.'modeh-frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'logo'.DIRECTORY_SEPARATOR.'modeh-logo.png',
            dirname(base_path()).DIRECTORY_SEPARATOR.'modeh-frontend'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'logo'.DIRECTORY_SEPARATOR.'logo.svg',
        ];

        $logoData = null;
        $logoIsSvg = false;
        foreach ($candidates as $p) {
            if (!$p) continue;
            if (file_exists($p) && is_readable($p)) {
                $type = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                $data = @file_get_contents($p);
                if ($data !== false) {
                    if ($type === 'svg') {
                        // Keep raw SVG so Blade can inline it for crisp rendering
                        $logoData = $data;
                        $logoIsSvg = true;
                    } else {
                        $logoData = 'data:image/'.$type.';base64,'.base64_encode($data);
                        $logoIsSvg = false;
                    }
                    break;
                }
            }
        }

        // Brand color used in PDF template
        $brandColor = '#7c3aed';

        $html = view('reports.quiz_analytics_pdf', [
            'quiz' => $quiz,
            'analytics' => $analyticsResponse,
            'logoData' => $logoData,
            'logoIsSvg' => $logoIsSvg,
            'brandColor' => $brandColor,
        ])->render();

        // Enable remote assets just in case, and render
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();

        $filename = "quiz-{$quiz->id}-analytics.pdf";
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename={$filename}"
        ]);
    }
}
