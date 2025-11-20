<?php

namespace App\Services;

/**
 * Shared service for marking questions across quizzes, battles, and daily challenges.
 * Encapsulates answer normalization, comparison, and scoring logic.
 */
class QuestionMarkingService
{
    /**
     * Build a map of option IDs/indices to text values for normalization
     */
    public function buildOptionMap($question): array
    {
        $optionMap = [];
        $options = is_array($question->options) ? $question->options : json_decode($question->options, true) ?? [];

        foreach ($options as $idx => $opt) {
            if (is_array($opt)) {
                if (isset($opt['id'])) {
                    $optionMap[(string)$opt['id']] = $opt['text'] ?? $opt['body'] ?? null;
                }
                if (isset($opt['text']) || isset($opt['body'])) {
                    $optionMap[(string)$idx] = $opt['text'] ?? $opt['body'] ?? null;
                }
            }
        }
        return $optionMap;
    }

    /**
     * Resolve a value (option id/index, object or text) to human-readable text
     */
    public function toText($val, array $optionMap = []): string
    {
        if (is_array($val) && (isset($val['body']) || isset($val['text']))) {
            return $val['text'] ?? $val['body'] ?? '';
        }
        if (!is_array($val)) {
            $key = (string)$val;
            if ($key !== '' && isset($optionMap[$key])) {
                return $optionMap[$key];
            }
        }
        return (string)$val;
    }

    /**
     * Normalize a single value for comparison (lowercase, trimmed)
     */
    public function normalizeForCompare($val, array $optionMap = []): string
    {
        $text = $this->toText($val, $optionMap);
        return strtolower(trim($text));
    }

    /**
     * Normalize an array of values for comparison: map -> trim/lower -> filter -> sort
     */
    public function normalizeArrayForCompare($arr, array $optionMap = []): array
    {
        $normalized = array_map(
            fn($v) => $this->normalizeForCompare($v, $optionMap),
            $arr ?: []
        );
        $normalized = array_filter($normalized, fn($v) => $v !== null && $v !== '');
        sort($normalized);
        return array_values($normalized);
    }

    /**
     * Check if user answer matches correct answer(s)
     */
    public function isAnswerCorrect($userAnswer, $correctAnswers, $question): bool
    {
        $optionMap = $this->buildOptionMap($question);
        $correctAnswersArray = is_array($correctAnswers) ? $correctAnswers : json_decode($correctAnswers, true) ?? [];

        if (is_array($userAnswer)) {
            $submittedNormalized = $this->normalizeArrayForCompare($userAnswer, $optionMap);
            $correctNormalized = $this->normalizeArrayForCompare($correctAnswersArray, $optionMap);
            return $submittedNormalized == $correctNormalized;
        } else {
            $submittedNormalized = $this->normalizeForCompare($userAnswer, $optionMap);
            $correctNormalized = $this->normalizeArrayForCompare($correctAnswersArray, $optionMap);
            return in_array($submittedNormalized, $correctNormalized);
        }
    }

    /**
     * Calculate score and correctness for a set of answers
     * Reusable for quizzes, battles, daily challenges
     *
     * @param array $answers The user's submitted answers (keyed by question_id or in quiz attempt format)
     * @param \Illuminate\Database\Eloquent\Collection $questions The questions to mark against
     * @param bool $isQuizAttemptFormat Whether answers are in quiz attempt format [{question_id: x, selected: y}]
     * @return array ['results' => array, 'correct_count' => int, 'score' => float]
     */
    public function calculateScore(array $answers, $questions, bool $isQuizAttemptFormat = false): array
    {
        $results = [];
        $correctCount = 0;
        $questionMap = $questions->keyBy('id');

        if ($isQuizAttemptFormat) {
            // Quiz attempt format: [{question_id: x, selected: y}, ...]
            foreach ($answers as $a) {
                $qid = intval($a['question_id'] ?? 0);
                $selected = $a['selected'] ?? null;
                $question = $questionMap->get($qid);
                if (!$question) continue;

                $correctAnswers = $question->answers ?? [];
                $isCorrect = $this->isAnswerCorrect($selected, $correctAnswers, $question);

                if ($isCorrect) $correctCount++;
                $results[] = ['question_id' => $qid, 'correct' => $isCorrect];
            }
        } else {
            // Direct format: {question_id: answer, ...}
            foreach ($answers as $questionId => $userAnswer) {
                $question = $questionMap->get($questionId);
                if (!$question) continue;

                $correctAnswers = $question->answers ?? [];
                $isCorrect = $this->isAnswerCorrect($userAnswer, $correctAnswers, $question);

                if ($isCorrect) $correctCount++;
                $results[] = ['question_id' => $questionId, 'correct' => $isCorrect];
            }
        }

        $attempted = max(1, count($answers));
        $score = round($correctCount / $attempted * 100, 1);

        return [
            'results' => $results,
            'correct_count' => $correctCount,
            'score' => $score,
        ];
    }
}
