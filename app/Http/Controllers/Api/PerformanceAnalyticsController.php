<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuizAttempt;
use App\Models\Question;
use App\Services\QuestionMarkingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        });

        // 3. Topic & Subject Analysis
        // We need to iterate through all questions answered by the user
        $topicStats = [];
        $subjectStats = [];
        $markingService = new QuestionMarkingService();

        foreach ($attempts as $attempt) {
            $answers = $attempt->answers ?? [];
            if (!is_array($answers)) continue;

            foreach ($answers as $ans) {
                $qid = $ans['question_id'] ?? null;
                if (!$qid) continue;

                $question = Question::with(['topics', 'subjects'])->find($qid);
                if (!$question) continue;

                $isCorrect = $markingService->isAnswerCorrect($ans['selected'] ?? null, $question->answers, $question);

                // Track by Topic
                foreach ($question->topics as $topic) {
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

                // Track by Subject
                foreach ($question->subjects as $subject) {
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

        return response()->json([
            'has_data' => true,
            'stats' => [
                'total_quizzes' => $totalQuizzes,
                'avg_score' => $avgScore,
                'total_time_seconds' => $totalTimeSeconds,
                'total_questions' => collect($topicStats)->sum('total'),
            ],
            'score_trend' => $scoreTrend,
            'subjects' => $formattedSubjects,
            'topics' => $formattedTopics->sortByDesc('total')->values(), // sorted by most practiced
            'weak_areas' => $weakTopics,
            'strong_areas' => $strongTopics,
        ]);
    }
}
