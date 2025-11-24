<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;

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

        // Basic aggregates using quiz_attempts table
        $attemptsQuery = QuizAttempt::query()->where('quiz_id', $quiz->id);
        $attemptsCount = $attemptsQuery->count();

        // completions: attempts with non-null score
        $completions = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('score')->count();

        $avgScore = round(QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('score')->avg('score') ?: 0, 2);
        $avgTime = round(QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('total_time_seconds')->avg('total_time_seconds') ?: 0, 2);

        // Load quiz questions to compute exact per-question correctness
        $quiz->load('questions');
        $questions = $quiz->questions;

        // Initialize per-question counters
        $perQuestion = [];
        foreach ($questions as $q) {
            $perQuestion[$q->id] = ['question_id' => $q->id, 'body' => $q->body, 'attempts_count' => 0, 'correct_count' => 0];
        }

        // Fetch attempts with answers
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('answers')->get();
        foreach ($attempts as $a) {
            $answers = $a->answers ?? [];
            if (!is_array($answers)) continue;
            foreach ($answers as $ans) {
                // ans expected shape: ['question_id' => x, 'selected' => ...]
                $qid = intval($ans['question_id'] ?? 0);
                if (!$qid || !isset($perQuestion[$qid])) continue;
                $perQuestion[$qid]['attempts_count'] += 1;

                $selected = $ans['selected'] ?? null;
                // find question model
                $qModel = $questions->firstWhere('id', $qid);
                if (!$qModel) continue;
                $correctAnswers = is_array($qModel->answers) ? $qModel->answers : json_decode($qModel->answers, true) ?? [];

                // Build option map (id/index -> text) to resolve numeric references and normalize for comparison
                $optionMap = [];
                if (is_array($qModel->options)) {
                    foreach ($qModel->options as $idx => $opt) {
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
                    $submitted = $normalizeArray($selected);
                    $correct = $normalizeArray(is_array($correctAnswers) ? $correctAnswers : []);
                    $isCorrect = ($submitted == $correct);
                } else {
                    $submitted = $normalizeForCompare($selected);
                    $correct = $normalizeArray(is_array($correctAnswers) ? $correctAnswers : []);
                    $isCorrect = in_array($submitted, $correct);
                }

                if ($isCorrect) $perQuestion[$qid]['correct_count'] += 1;
            }
        }

        // Flatten perQuestion and compute rates
        $perQuestionFlat = [];
        foreach ($perQuestion as $p) {
            $attemptsC = $p['attempts_count'];
            $correctC = $p['correct_count'];
            $rate = $attemptsC ? round($correctC / $attemptsC, 3) : null;
            $perQuestionFlat[] = array_merge($p, ['correct_rate' => $rate]);
        }

        // Score distribution (deciles: 0-9,10-19,...,100)
        $distribution = array_fill(0, 11, 0);
        $scoreRows = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('score')->get(['score']);
        foreach ($scoreRows as $r) {
            $s = (int)round($r->score);
            $bucket = min(10, (int)floor($s / 10));
            $distribution[$bucket]++;
        }

        // Attempts trend (last 30 days)
        $trendStart = now()->subDays(29)->startOfDay();
        $rawTrend = \DB::table('quiz_attempts')
            ->select(\DB::raw("DATE(created_at) as day"), \DB::raw('count(*) as cnt'))
            ->where('quiz_id', $quiz->id)
            ->where('created_at', '>=', $trendStart)
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('cnt', 'day')
            ->toArray();

        // Build an array for last 30 days
        $trend = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $trendStart->copy()->addDays($i)->format('Y-m-d');
            $trend[] = isset($rawTrend[$d]) ? (int)$rawTrend[$d] : 0;
        }

        // Top missed questions (lowest correct rate)
        usort($perQuestionFlat, function($a, $b) {
            $ra = $a['correct_rate'] ?? 0; $rb = $b['correct_rate'] ?? 0; return $ra <=> $rb;
        });
        $topMissed = array_slice($perQuestionFlat, 0, 5);

        return response()->json([
            'attempts_count' => $attemptsCount,
            'completions' => $completions,
            'avg_score' => $avgScore,
            'avg_time_seconds' => $avgTime,
            'per_question' => $perQuestionFlat,
            'score_distribution' => $distribution,
            'attempts_trend' => $trend,
            'top_missed_questions' => $topMissed,
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
                    $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
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
