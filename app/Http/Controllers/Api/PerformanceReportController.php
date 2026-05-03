<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\OneOffPurchase;
use App\Models\PricingSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\QuestionMarkingService;

class PerformanceReportController extends Controller
{
    protected $markingService;

    public function __construct(QuestionMarkingService $markingService)
    {
        $this->markingService = $markingService;
        $this->middleware('auth:sanctum');
    }

    /**
     * Get performance report analysis (weak areas).
     */
    public function show(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $report = $this->generateAnalysis($attempt);
        
        // Check if report is paid
        $isPaid = OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'performance_report')
            ->where('item_id', $attempt->id)
            ->where('status', 'confirmed')
            ->exists();

        // If not paid, we might want to hide some details or just return a flag
        return response()->json([
            'ok' => true,
            'report' => $report,
            'is_paid' => $isPaid,
            'price' => PricingSetting::singleton()->performance_report_price ?? 0.0,
        ]);
    }

    /**
     * Download PDF report.
     */
    public function download(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        // Enforce payment
        $isPaid = OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'performance_report')
            ->where('item_id', $attempt->id)
            ->where('status', 'confirmed')
            ->exists();

        if (!$isPaid && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Payment required to download report'], 402);
        }

        $report = $this->generateAnalysis($attempt);
        
        // Generate PDF using DOMPDF (reusing pattern from QuizAnalyticsController)
        $html = view('reports.performance_report_pdf', [
            'attempt' => $attempt,
            'report' => $report,
            'user' => $user,
            'brandColor' => '#7c3aed',
        ])->render();

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $filename = "performance-report-attempt-{$attempt->id}.pdf";
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename={$filename}"
        ]);
    }

    private function generateAnalysis(QuizAttempt $attempt)
    {
        $quiz = $attempt->quiz()->with('questions.topic')->first();
        $answers = $attempt->answers ?? [];
        $topicsData = [];

        foreach ($quiz->questions as $q) {
            $topicId = $q->topic_id ?? 0;
            $topicName = $q->topic->title ?? $q->topic->name ?? 'General';

            if (!isset($topicsData[$topicId])) {
                $topicsData[$topicId] = [
                    'id' => $topicId,
                    'name' => $topicName,
                    'total' => 0,
                    'correct' => 0,
                    'questions' => []
                ];
            }

            $topicsData[$topicId]['total']++;

            // Check if user answer was correct
            $userAnswer = null;
            foreach ($answers as $ans) {
                if ((int)($ans['question_id'] ?? 0) === (int)$q->id) {
                    $userAnswer = $ans['selected'] ?? null;
                    break;
                }
            }

            $isCorrect = $this->markingService->isAnswerCorrect($userAnswer, $q->answers, $q);
            if ($isCorrect) {
                $topicsData[$topicId]['correct']++;
            }

            $topicsData[$topicId]['questions'][] = [
                'body' => $q->body,
                'is_correct' => $isCorrect,
                'explanation' => $q->explanation
            ];
        }

        // Calculate percentages and identify weak areas
        $analysis = [];
        foreach ($topicsData as $id => $data) {
            $percentage = ($data['total'] > 0) ? round(($data['correct'] / $data['total']) * 100, 1) : 0;
            $analysis[] = [
                'topic_id' => $id,
                'topic_name' => $data['name'],
                'total_questions' => $data['total'],
                'correct_answers' => $data['correct'],
                'percentage' => $percentage,
                'status' => $this->getStatus($percentage),
                'recommendation' => $this->getRecommendation($percentage, $data['name'])
            ];
        }

        return [
            'topics_breakdown' => $analysis,
            'weak_areas' => array_values(array_filter($analysis, fn($t) => $t['percentage'] < 60)),
            'strong_areas' => array_values(array_filter($analysis, fn($t) => $t['percentage'] >= 80)),
            'detailed_questions' => $this->getDetailedQuestions($quiz, $answers),
            'stats' => [
                'score' => $attempt->score,
                'total_questions' => $quiz->questions->count(),
                'correct_count' => $attempt->correct_count ?? count(array_filter($analysis, fn($t) => $t['correct_answers'])),
                'time_taken' => $attempt->total_time_seconds,
            ]
        ];
    }

    private function getDetailedQuestions($quiz, $answers)
    {
        $details = [];
        foreach ($quiz->questions as $q) {
            $userAnswer = null;
            foreach ($answers as $ans) {
                if ((int)($ans['question_id'] ?? 0) === (int)$q->id) {
                    $userAnswer = $ans['selected'] ?? null;
                    break;
                }
            }

            $isCorrect = $this->markingService->isAnswerCorrect($userAnswer, $q->answers, $q);
            $optionMap = $this->markingService->buildOptionMap($q);

            $details[] = [
                'body' => $q->body,
                'is_correct' => $isCorrect,
                'user_answer' => $this->markingService->formatExplanationAnswers($userAnswer, $optionMap),
                'correct_answer' => $this->markingService->formatExplanationAnswers($q->answers, $optionMap),
                'explanation' => $q->explanation,
                'topic' => $q->topic->name ?? 'General',
            ];
        }
        return $details;
    }

    private function getStatus($percentage)
    {
        if ($percentage >= 80) return 'Strong';
        if ($percentage >= 60) return 'Good';
        if ($percentage >= 40) return 'Average';
        return 'Weak';
    }

    private function getRecommendation($percentage, $topicName)
    {
        if ($percentage >= 80) return "You have a solid understanding of $topicName. Keep it up!";
        if ($percentage >= 60) return "Good job on $topicName. A bit more practice will make you an expert.";
        if ($percentage >= 40) return "Consider reviewing the core concepts of $topicName and trying more practice questions.";
        return "This is a priority area for improvement. We recommend focusing on $topicName fundamentals.";
    }
}
