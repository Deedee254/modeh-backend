<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\Question;
use App\Services\QuestionMarkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PerformanceAnalyticsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get aggregate performance analytics for the authenticated quizee.
     */
    public function overview(Request $request)
    {
        try {
            $user = $request->user();
            
            // 1. Basic Stats
            $attempts = QuizAttempt::where('user_id', $user->id)
                ->whereNotNull('score')
                ->orderBy('created_at', 'asc')
                ->get();

            if ($attempts->isEmpty()) {
                return response()->json([
                    'has_data' => false,
                    'stats' => [
                        'total_quizzes' => 0,
                        'avg_score' => 0,
                        'total_time' => 0,
                    ]
                ]);
            }

            $totalQuizzes = $attempts->count();
            $avgScore = round($attempts->avg('score'), 1);
            $totalTimeSeconds = $attempts->sum('total_time_seconds');

            // 2. Score Trend
            $scoreTrend = $attempts->take(-20)->map(function($a) {
                return [
                    'date' => $a->created_at->format('M d'),
                    'score' => round($a->score, 1),
                    'quiz_title' => $a->quiz->title ?? 'Quiz',
                ];
            })->values();

            // 3. Topic & Subject Analysis
            // Collect all unique question IDs to batch fetch
            $allQuestionIds = [];
            foreach ($attempts as $attempt) {
                $answers = $attempt->answers ?? [];
                if (!is_array($answers)) continue;
                foreach ($answers as $ans) {
                    if (isset($ans['question_id'])) {
                        $allQuestionIds[] = $ans['question_id'];
                    }
                }
            }
            $allQuestionIds = array_unique($allQuestionIds);

            // Fetch all questions with their taxonomy in one go
            $questionsMap = Question::with(['topic', 'subject'])
                ->whereIn('id', $allQuestionIds)
                ->get()
                ->keyBy('id');

            $topicStats = [];
            $subjectStats = [];
            $markingService = new QuestionMarkingService();
            $totalQuestionsProcessed = 0;

            foreach ($attempts as $attempt) {
                $answers = $attempt->answers ?? [];
                if (!is_array($answers)) continue;

                foreach ($answers as $ans) {
                    $qid = $ans['question_id'] ?? null;
                    if (!$qid) continue;

                    $question = $questionsMap->get($qid);
                    if (!$question) continue;

                    $isCorrect = $markingService->isAnswerCorrect($ans['selected'] ?? null, $question->answers, $question);
                    $totalQuestionsProcessed++;

                    // Track by Topic (Singular in this DB schema)
                    if ($question->topic) {
                        $topic = $question->topic;
                        if (!isset($topicStats[$topic->id])) {
                            $topicStats[$topic->id] = [
                                'id' => $topic->id,
                                'name' => $topic->name,
                                'correct' => 0,
                                'total' => 0,
                            ];
                        }
                        $topicStats[$topic->id]['total']++;
                        if ($isCorrect) $topicStats[$topic->id]['correct']++;
                    }

                    // Track by Subject (Singular in this DB schema)
                    if ($question->subject) {
                        $subject = $question->subject;
                        if (!isset($subjectStats[$subject->id])) {
                            $subjectStats[$subject->id] = [
                                'id' => $subject->id,
                                'name' => $subject->name,
                                'correct' => 0,
                                'total' => 0,
                            ];
                        }
                        $subjectStats[$subject->id]['total']++;
                        if ($isCorrect) $subjectStats[$subject->id]['correct']++;
                    }
                }
            }

            // Calculate percentages and sort
            $formattedTopics = collect($topicStats)->map(function($item) {
                $item['percentage'] = round(($item['correct'] / $item['total']) * 100, 1);
                return $item;
            })->values();

            $formattedSubjects = collect($subjectStats)->map(function($item) {
                $item['percentage'] = round(($item['correct'] / $item['total']) * 100, 1);
                return $item;
            })->values();

            $weakTopics = $formattedTopics->sortBy('percentage')->take(5)->values();
            $strongTopics = $formattedTopics->sortByDesc('percentage')->take(5)->values();

            // 4. Advanced Insights
            $proficiencyLevel = $this->calculateProficiencyLevel($avgScore);
            $consistencyScore = $this->calculateConsistencyScore($attempts);
            $learningVelocity = $this->calculateLearningVelocity($attempts);

            return response()->json([
                'has_data' => true,
                'stats' => [
                    'total_quizzes' => $totalQuizzes,
                    'avg_score' => $avgScore,
                    'total_time_seconds' => $totalTimeSeconds,
                    'total_questions' => $totalQuestionsProcessed,
                    'avg_time_per_question' => $totalQuestionsProcessed > 0 ? round($totalTimeSeconds / $totalQuestionsProcessed, 1) : 0,
                    'proficiency' => $proficiencyLevel,
                    'consistency' => $consistencyScore,
                    'velocity' => $learningVelocity,
                ],
                'score_trend' => $scoreTrend,
                'subjects' => $formattedSubjects,
                'topics' => $formattedTopics->sortByDesc('total')->values(),
                'weak_areas' => $weakTopics,
                'strong_areas' => $strongTopics,
            ]);

        } catch (\Exception $e) {
            Log::error('Performance Analytics Error: ' . $e->getMessage(), [
                'user_id' => $user->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'An error occurred while generating your performance analytics.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function calculateProficiencyLevel($score)
    {
        if ($score >= 80) return ['label' => 'Exceptional', 'grade' => 'A+', 'color' => '#10b981'];
        if ($score >= 70) return ['label' => 'Distinction', 'grade' => 'A', 'color' => '#059669'];
        if ($score >= 60) return ['label' => 'Merit', 'grade' => 'B', 'color' => '#3b82f6'];
        if ($score >= 50) return ['label' => 'Satisfactory', 'grade' => 'C', 'color' => '#f59e0b'];
        return ['label' => 'Developing', 'grade' => 'D', 'color' => '#ef4444'];
    }

    private function calculateConsistencyScore($attempts)
    {
        if ($attempts->count() < 3) return 0;
        
        $daysWorked = $attempts->groupBy(fn($a) => $a->created_at->format('Y-m-d'))->count();
        $totalDays = $attempts->first()->created_at->diffInDays(now()) ?: 1;
        
        // Ratio of active days to total days in period, scaled to 100
        return min(100, round(($daysWorked / min(30, $totalDays)) * 100));
    }

    private function calculateLearningVelocity($attempts)
    {
        if ($attempts->count() < 5) return 'Stable';
        
        $firstHalf = $attempts->take($attempts->count() / 2)->avg('score');
        $secondHalf = $attempts->take(-($attempts->count() / 2))->avg('score');
        
        $diff = $secondHalf - $firstHalf;
        if ($diff > 5) return 'Improving';
        if ($diff < -5) return 'Declining';
        return 'Stable';
    }

    /**
     * Download aggregate performance analytics as PDF.
     */
    public function download(Request $request)
    {
        $data = $this->overview($request)->getData(true);
        
        if (!($data['has_data'] ?? false)) {
            return response()->json(['message' => 'No data available to generate report'], 404);
        }

        $html = view('reports.performance_overview_pdf', [
            'user' => $request->user(),
            'data' => $data,
            'brandColor' => '#7c3aed',
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $filename = "performance-overview-" . now()->format('Y-m-d') . ".pdf";
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename={$filename}"
        ]);
    }
}
