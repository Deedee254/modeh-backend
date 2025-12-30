<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GuestQuizController extends Controller
{
    /**
     * Get quiz questions for guest (excludes is_correct from options)
     * Only allow free quizzes
     *
     * @param  \App\Models\Quiz $quiz
     * @return \Illuminate\Http\JsonResponse
     */
    public function getQuestions(Quiz $quiz)
    {
        // Check if quiz is premium/paid - guests can only take free quizzes
        if ($quiz->is_paid) {
            return response()->json([
                'error' => 'This quiz requires authentication. Please login or register to continue.',
                'code' => 'PREMIUM_QUIZ'
            ], 403);
        }

        // Load questions with options
        $quiz->load(['questions']);

        // Prepare questions (apply shuffling if configured)
        $prepared = $quiz->getPreparedQuestions();
        $questions = [];

        foreach ($prepared as $q) {
            $questionData = [
                'id' => isset($q['id']) ? $q['id'] : (isset($q->id) ? $q->id : null),
                'type' => isset($q['type']) ? $q['type'] : (isset($q->type) ? $q->type : null),
                'body' => isset($q['body']) ? $q['body'] : (isset($q->body) ? $q->body : (isset($q['text']) ? $q['text'] : '')),
                'marks' => isset($q['marks']) ? $q['marks'] : (isset($q->marks) ? $q->marks : 1),
                'media_path' => isset($q['media_path']) ? $q['media_path'] : (isset($q->media_path) ? $q->media_path : null),
            ];

            // Include options WITHOUT the is_correct field to prevent cheating
            $options = isset($q['options']) ? $q['options'] : (isset($q->options) ? $q->options : []);
            $cleanedOptions = [];

            if (is_array($options)) {
                foreach ($options as $opt) {
                    if (is_array($opt)) {
                        // Remove is_correct from option
                        $cleanedOption = [
                            'id' => $opt['id'] ?? null,
                            'text' => $opt['text'] ?? null,
                            'body' => $opt['body'] ?? null,
                        ];
                        $cleanedOptions[] = array_filter($cleanedOption); // Remove null values
                    } else {
                        $cleanedOptions[] = $opt;
                    }
                }
            }

            $questionData['options'] = $cleanedOptions;
            $questions[] = $questionData;
        }

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'timer_seconds' => $quiz->timer_seconds,
                'per_question_seconds' => $quiz->per_question_seconds,
                'use_per_question_timer' => (bool) $quiz->use_per_question_timer,
                'shuffle_questions' => (bool) $quiz->shuffle_questions,
                'shuffle_answers' => (bool) $quiz->shuffle_answers,
                'questions' => $questions,
            ],
        ]);
    }

    /**
     * Submit guest quiz answers and get results with server-side marking
     * Only allow free quizzes
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Quiz  $quiz
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit(Request $request, Quiz $quiz)
    {
        // Check if quiz is premium/paid
        if ($quiz->is_paid) {
            return response()->json([
                'error' => 'This quiz requires authentication. Please login or register to continue.',
                'code' => 'PREMIUM_QUIZ'
            ], 403);
        }

        // Validate submission
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.selected' => 'nullable',
            'time_taken' => 'nullable|integer',
            'guest_identifier' => 'required|string',
        ]);

        // Load questions
        $questions = $quiz->questions()->get();

        // Calculate score
        $scoringResult = $this->calculateScore($validated['answers'], $questions);

        // Prepare minimal result payload for guests (no per-question breakdown)
        // Use quiz questions count as total so omitted answers are treated as incorrect
        $totalQuestions = $questions->count();
        $correctCount = $scoringResult['correct_count'] ?? 0;

        $result = [
            'quiz_id' => $quiz->id,
            'quiz_title' => $quiz->title,
            'quiz_slug' => $quiz->slug,
            'guest_identifier' => $validated['guest_identifier'],
            'score' => $scoringResult['score'],
            'percentage' => $scoringResult['score'],
            'correct_count' => $correctCount,
            // Treat unanswered/omitted questions as incorrect by deriving incorrect count
            'incorrect_count' => max(0, $totalQuestions - $correctCount),
            'total_questions' => $totalQuestions,
            'time_taken' => $validated['time_taken'] ?? 0,
            'attempted_at' => now()->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'attempt' => $result
        ]);
    }

    /**
     * Mark a single question for a guest (server-side) without exposing all answers.
     * Accepts: question_id, selected (id or array), guest_identifier (optional)
     */
    public function markQuestion(Request $request, Quiz $quiz)
    {
        $v = Validator::make($request->all(), [
            'question_id' => 'required|integer',
            'selected' => 'nullable',
            'guest_identifier' => 'nullable|string',
        ]);

        if ($v->fails()) {
            return response()->json(['error' => 'Invalid input', 'errors' => $v->errors()], 422);
        }

        $questionId = (int) $request->get('question_id');
        $selected = $request->get('selected');

        $question = $quiz->questions()->where('id', $questionId)->first();
        if (!$question) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        // Determine correct answers for question
        if (is_array($question->answers)) {
            $correctAnswers = $question->answers;
        } elseif (is_string($question->answers) && $question->answers !== '') {
            $decoded = json_decode($question->answers, true);
            $correctAnswers = is_array($decoded) ? $decoded : [];
        } else {
            $correctAnswers = [];
        }

        $optionMap = $this->buildOptionMap($question);

        $isCorrect = false;
        if (is_array($selected)) {
            $submitted = $this->normalizeArrayForCompare($selected, $optionMap);
            $correctNorm = $this->normalizeArrayForCompare($correctAnswers, $optionMap);
            $isCorrect = ($submitted == $correctNorm);
        } else {
            $submitted = $this->normalizeForCompare($selected, $optionMap);
            $correctNormArr = $this->normalizeArrayForCompare($correctAnswers, $optionMap);
            $isCorrect = in_array($submitted, $correctNormArr);
        }

        return response()->json([
            'correct' => (bool) $isCorrect,
            'explanation' => $question->explanation ?? null,
            'correct_answer' => $this->formatAnswer($question->answers),
        ]);
    }

    /**
     * Format results with question details and explanations
     *
     * @param  array  $results
     * @param  \Illuminate\Database\Eloquent\Collection  $questions
     * @return array
     */
    private function formatResultsWithExplanations(array $results, $questions): array
    {
        $questionMap = $questions->keyBy('id');
        $formatted = [];

        foreach ($results as $result) {
            $question = $questionMap->get($result['question_id']);

            if (!$question) {
                continue;
            }

            $formatted[] = [
                'question_id' => $result['question_id'],
                'question_body' => $question->body ?? $question->text ?? '',
                'is_correct' => $result['correct'],
                'marks_earned' => $result['marks'],
                'explanation' => $question->explanation ?? null,
                'correct_answer' => $this->formatAnswer($question->answers),
            ];
        }

        return $formatted;
    }

    /**
     * Format answer for display
     *
     * @param  mixed  $answer
     * @return string
     */
    private function formatAnswer($answer): string
    {
        if (is_array($answer)) {
            return implode(', ', $answer);
        }

        if (is_string($answer)) {
            $decoded = json_decode($answer, true);
            if (is_array($decoded)) {
                return implode(', ', $decoded);
            }
        }

        return (string) $answer;
    }

    /**
     * Build a map of option id/index => display text for a question's options
     *
     * @param  mixed $q Question model or object with options
     * @return array
     */
    private function buildOptionMap($q): array
    {
        $optionMap = [];
        if (is_array($q->options)) {
            foreach ($q->options as $idx => $opt) {
                if (is_array($opt)) {
                    if (isset($opt['id'])) {
                        $optionMap[(string) $opt['id']] = $opt['text'] ?? $opt['body'] ?? null;
                    }
                    if (isset($opt['text']) || isset($opt['body'])) {
                        $optionMap[(string) $idx] = $opt['text'] ?? $opt['body'] ?? null;
                    }
                }
            }
        }
        return $optionMap;
    }

    /**
     * Resolve a value (option id/index, object or text) to a human-readable text
     */
    private function toText($val, array $optionMap = []): string
    {
        if (is_array($val) && (isset($val['body']) || isset($val['text']))) {
            return $val['text'] ?? $val['body'] ?? '';
        }
        if (!is_array($val)) {
            $key = (string) $val;
            if ($key !== '' && isset($optionMap[$key])) {
                return $optionMap[$key];
            }
        }
        return (string) $val;
    }

    /**
     * Normalize a single value for comparison (lowercase, trimmed)
     */
    private function normalizeForCompare($val, array $optionMap = []): string
    {
        $text = $this->toText($val, $optionMap);
        return strtolower(trim((string) $text));
    }

    /**
     * Normalize an array of values for comparison: map -> trim/lower -> filter -> sort
     */
    private function normalizeArrayForCompare($arr, array $optionMap = []): array
    {
        $normalized = array_map(function ($v) use ($optionMap) {
            return $this->normalizeForCompare($v, $optionMap);
        }, $arr ?: []);
        $normalized = array_filter($normalized, function ($v) {
            return $v !== null && $v !== '';
        });
        sort($normalized);
        return array_values($normalized);
    }

    /**
     * Calculate score for guest submission
     *
     * @param  array  $answers
     * @param  \Illuminate\Database\Eloquent\Collection  $questions
     * @return array
     */
    private function calculateScore(array $answers, $questions): array
    {
        $results = [];
        $correctCount = 0;
        $earnedMarks = 0;
        $totalPossibleMarks = 0;
        $questionMap = $questions->keyBy('id');

        foreach ($answers as $a) {
            $qid = intval($a['question_id'] ?? 0);
            $selected = $a['selected'] ?? null;
            $q = $questionMap->get($qid);

            if (!$q) {
                continue;
            }

            // Determine question weight (marks), default to 1 if not set
            $weight = floatval($q->marks) ?: 1.0;
            $totalPossibleMarks += $weight;

            $isCorrect = false;

            // Get correct answers from question
            if (is_array($q->answers)) {
                $correctAnswers = $q->answers;
            } elseif (is_string($q->answers) && $q->answers !== '') {
                $decoded = json_decode($q->answers, true);
                $correctAnswers = is_array($decoded) ? $decoded : [];
            } else {
                $correctAnswers = [];
            }

            $optionMap = $this->buildOptionMap($q);

            // Compare submitted answer with correct answers
            if (is_array($selected)) {
                $submittedAnswers = $this->normalizeArrayForCompare($selected, $optionMap);
                $correctAnswersNormalized = $this->normalizeArrayForCompare($correctAnswers, $optionMap);
                $isCorrect = $submittedAnswers == $correctAnswersNormalized;
            } else {
                $submittedAnswer = $this->normalizeForCompare($selected, $optionMap);
                $correctAnswersNormalized = $this->normalizeArrayForCompare($correctAnswers, $optionMap);
                $isCorrect = in_array($submittedAnswer, $correctAnswersNormalized);
            }

            if ($isCorrect) {
                $correctCount++;
                $earnedMarks += $weight;
            }

            $results[] = [
                'question_id' => $qid,
                'correct' => $isCorrect,
                'marks' => $isCorrect ? $weight : 0
            ];
        }

        // Calculate total quiz marks for percentage calculation
        $totalQuizMarks = 0;
        foreach ($questions as $q) {
            $totalQuizMarks += (floatval($q->marks) ?: 1.0);
        }

        $score = $totalQuizMarks > 0 ? round(($earnedMarks / $totalQuizMarks) * 100, 1) : 0;

        return [
            'results' => $results,
            'correct_count' => $correctCount,
            'score' => $score,
            'earned_marks' => $earnedMarks,
            'total_marks' => $totalQuizMarks
        ];
    }
}
