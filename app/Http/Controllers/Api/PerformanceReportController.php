<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\OneOffPurchase;
use App\Models\PricingSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\QuestionMarkingService;
use App\Services\QuizAccessService;

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
     * 
     * Access is granted if:
     * 1. User is the attempt owner
     * 2. Quiz is free (no separate payment for report), OR
     * 3. User has paid for the quiz OR the report separately
     */
    public function show(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $quiz = $attempt->quiz;
        if (!$quiz) {
            return response()->json(['ok' => false, 'message' => 'Quiz not found'], 404);
        }

        // Check if user has access to view this report
        $accessResult = QuizAccessService::checkAccess($quiz, $user);
        
        // Check if quiz is free - if yes, user can view report
        $quizIsFree = $accessResult['is_free'] ?? false;
        
        // Check if user has paid for the report separately
        $reportIsPaid = OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'performance_report')
            ->where('item_id', $attempt->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->exists();
        
        // Check if user paid for the quiz (if not free)
        $quizIsPaid = !$quizIsFree ? QuizAccessService::hasAccessOrPaid($quiz, $user) : false;
        
        // Allow access if: quiz is free OR user paid for quiz OR user paid for report
        $canViewReport = $quizIsFree || $quizIsPaid || $reportIsPaid || $user->is_admin;
        
        if (!$canViewReport) {
            $price = PricingSetting::singleton()->performance_report_price ?? 0.0;
            return response()->json([
                'ok' => false,
                'message' => 'Payment required to view performance report',
                'requires_payment' => true,
                'price' => $price,
                'attempt_id' => $attempt->id,
            ], 403);
        }

        $report = $this->generateAnalysis($attempt);
        $price = PricingSetting::singleton()->performance_report_price ?? 0.0;
        
        return response()->json([
            'ok' => true,
            'report' => $report,
            'is_paid' => $reportIsPaid,
            'price' => $price,
            'quiz_is_free' => $quizIsFree,
        ]);
    }

    /**
     * Download PDF report.
     * 
     * Access is granted if:
     * 1. User is the attempt owner
     * 2. Quiz is free (no separate payment for report), OR
     * 3. User has paid for the quiz OR the report separately
     */
    public function download(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if ($attempt->user_id !== $user->id && !$user->is_admin) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $quiz = $attempt->quiz;
        if (!$quiz) {
            return response()->json(['ok' => false, 'message' => 'Quiz not found'], 404);
        }

        // Check if user has access to download this report (same rules as show())
        $accessResult = QuizAccessService::checkAccess($quiz, $user);
        $quizIsFree = $accessResult['is_free'] ?? false;
        
        // Check if user has paid for the report separately
        $reportIsPaid = OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'performance_report')
            ->where('item_id', $attempt->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->exists();
        
        // Check if user paid for the quiz (if not free)
        $quizIsPaid = !$quizIsFree ? QuizAccessService::hasAccessOrPaid($quiz, $user) : false;
        
        // Allow download if: quiz is free OR user paid for quiz OR user paid for report
        $canDownload = $quizIsFree || $quizIsPaid || $reportIsPaid || $user->is_admin;
        
        if (!$canDownload) {
            $price = PricingSetting::singleton()->performance_report_price ?? 0.0;
            return response()->json([
                'ok' => false,
                'message' => 'Payment required to download report',
                'requires_payment' => true,
                'price' => $price,
                'attempt_id' => $attempt->id,
            ], 402);
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

    public function generateAnalysis($attempt)
    {
        $assessment = null;
        if (method_exists($attempt, 'quiz') && $attempt->quiz) {
            $assessment = $attempt->quiz()->with('questions.topic')->first();
        } elseif (method_exists($attempt, 'tournament') && $attempt->tournament) {
            $assessment = $attempt->tournament()->with('questions.topic')->first();
        } elseif (method_exists($attempt, 'battle') && $attempt->battle) {
            $assessment = $attempt->battle()->with('questions.topic')->first();
        }

        if (!$assessment) {
            return [];
        }

        $answers = $attempt->answers ?? [];
        $perQuestionTime = $attempt->per_question_time ?? [];
        $topicsData = [];

        foreach ($assessment->questions as $q) {
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

        $weakAreas = array_values(array_filter($analysis, fn($t) => $t['percentage'] < 60));
        $weakTopicIds = array_column($weakAreas, 'topic_id');
        
        $recommendedQuizzes = [];
        if (!empty($weakTopicIds)) {
            // Fetch some active quizzes from these topics
            $recommendedQuizzes = \App\Models\Quiz::whereIn('topic_id', $weakTopicIds)
                ->where('is_approved', true)
                ->where('is_draft', false)
                ->where('id', '!=', $assessment->id) // don't recommend the same quiz
                ->withCount('questions')
                ->inRandomOrder()
                ->limit(3)
                ->get()
                ->map(function($q) {
                    return [
                        'title' => $q->title,
                        'description' => $q->description,
                        'topic' => $q->topic->title ?? $q->topic->name ?? 'General',
                        'questions_count' => $q->questions_count
                    ];
                })
                ->toArray();
        }

        return [
            'topics_breakdown' => $analysis,
            'weak_areas' => $weakAreas,
            'strong_areas' => array_values(array_filter($analysis, fn($t) => $t['percentage'] >= 80)),
            'detailed_questions' => $this->getDetailedQuestions($assessment, $answers, $perQuestionTime),
            'recommended_quizzes' => $recommendedQuizzes,
            'stats' => [
                'score' => $attempt->score,
                'total_questions' => $assessment->questions->count(),
                'correct_count' => $attempt->correct_count ?? count(array_filter($analysis, fn($t) => $t['correct_answers'])),
                'time_taken' => $attempt->total_time_seconds ?? $attempt->duration_seconds ?? 0,
            ]
        ];
    }

    private function getDetailedQuestions($assessment, $answers, $perQuestionTime = [])
    {
        $details = [];
        foreach ($assessment->questions as $q) {
            $userAnswer = null;
            foreach ($answers as $ans) {
                if ((int)($ans['question_id'] ?? 0) === (int)$q->id) {
                    $userAnswer = $ans['selected'] ?? null;
                    break;
                }
            }

            $isCorrect = $this->markingService->isAnswerCorrect($userAnswer, $q->answers, $q);
            $optionMap = $this->markingService->buildOptionMap($q);
            $timeTaken = $perQuestionTime[$q->id] ?? null;

            $details[] = [
                'body' => $q->body,
                'is_correct' => $isCorrect,
                'user_answer' => $this->markingService->formatExplanationAnswers($userAnswer, $optionMap),
                'correct_answer' => $this->markingService->formatExplanationAnswers($q->answers, $optionMap),
                'time_taken' => $timeTaken,
                'explanation' => $q->explanation,
                'topic' => $q->topic->title ?? $q->topic->name ?? 'General',
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
