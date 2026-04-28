<?php

namespace App\Services;

/**
 * Shared service for marking questions across quizzes, battles, and daily challenges.
 * Encapsulates answer normalization, comparison, and scoring logic.
 */
use App\Traits\SeedableShuffle;

class QuestionMarkingService
{
    use SeedableShuffle;
    /**
     * Build a map of option IDs/indices to text values for normalization
     */
    /**
     * Build a map of option IDs/indices to text values for normalization
     */
    public function buildOptionMap($question, ?string $shuffleSeed = null): array
    {
        $optionMap = [];
        $options = $question->options;
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }
        if (!is_array($options)) {
            $options = [];
        }

        // Apply shuffling if a seed is provided
        if ($shuffleSeed && $shuffleSeed !== '') {
            $options = $this->seededShuffle($options, $shuffleSeed . '::' . $question->id);
        }

        foreach ($options as $idx => $opt) {
            if (is_array($opt)) {
                $text = $opt['text'] ?? $opt['body'] ?? $opt['option'] ?? null;
                if (isset($opt['id'])) {
                    $optionMap[(string)$opt['id']] = $text;
                }
                if ($text !== null) {
                    $optionMap[(string)$idx] = $text;
                }
            } else {
                $optionMap[(string)$idx] = (string)$opt;
            }
        }
        return $optionMap;
    }

    /**
     * Resolve a value (option id/index, object or text) to human-readable text
     */
    public function toText($val, array $optionMap = []): string
    {
        if (is_array($val)) {
            return $val['text'] ?? $val['body'] ?? $val['option'] ?? (string)json_encode($val);
        }
        
        $key = (string)$val;
        if ($key !== '' && isset($optionMap[$key])) {
            return (string)$optionMap[$key];
        }

        // If submitted as normalized text, recover canonical option casing where possible.
        $needle = strtolower(trim($key));
        if ($needle !== '') {
            foreach ($optionMap as $txt) {
                if (strtolower(trim((string) $txt)) === $needle) {
                    return (string) $txt;
                }
            }
        }
        
        return $key;
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
     * Resolve a value or array of values to human-readable text labels.
     * Useful for formatting result breakdowns.
     */
    public function resolveToText($val, array $optionMap = []): string|array
    {
        if (is_array($val)) {
            return array_map(fn($v) => $this->toText($v, $optionMap), $val);
        }
        return $this->toText($val, $optionMap);
    }

    /**
     * Formats an answer or list of answers into a comma-separated string of display texts.
     */
    public function formatExplanationAnswers($answers, array $optionMap = []): string
    {
        if (is_null($answers) || $answers === '') return '';
        
        $resolved = $this->resolveToText($answers, $optionMap);
        if (is_array($resolved)) {
            return implode(', ', array_filter($resolved, fn($v) => $v !== null && $v !== ''));
        }
        return (string)$resolved;
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
    public function isAnswerCorrect($userAnswer, $correctAnswers, $question, string $shuffleSeed = ''): bool
    {
        // If shuffled, unmap the answer first
        if ($shuffleSeed !== '') {
            $userAnswer = $this->unmapShuffledAnswer($userAnswer, $question, $shuffleSeed);
        }

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
     * @param string $shuffleSeed Optional seed if answers were shuffled
     * @return array ['results' => array, 'correct_count' => int, 'score' => float]
     */
    public function calculateScore(array $answers, $questions, bool $isQuizAttemptFormat = false, string $shuffleSeed = '', bool $skipUnmapping = false): array
    {
        $results = [];
        $correctCount = 0;
        $earnedMarks = 0.0;
        $totalPossibleMarks = 0.0;
        $questionMap = $questions->keyBy('id');

        // First, build a map of ALL questions to calculate total possible marks
        foreach ($questions as $q) {
            $totalPossibleMarks += (float)($q->marks ?: 1);
        }

        if ($isQuizAttemptFormat) {
            foreach ($answers as $a) {
                $qid = intval($a['question_id'] ?? 0);
                $selected = $a['selected'] ?? null;
                $question = $questionMap->get($qid);
                if (!$question) continue;

                $weight = (float)($question->marks ?: 1);
                $isCorrect = $this->isAnswerCorrect($selected, $question->answers, $question, $skipUnmapping ? '' : $shuffleSeed);

                if ($isCorrect) {
                    $correctCount++;
                    $earnedMarks += $weight;
                }
                $optionMap = $this->buildOptionMap($question, $shuffleSeed);
                $results[] = [
                    'question_id' => $qid, 
                    'correct' => $isCorrect, 
                    'selected' => $selected,
                    'selected_text' => $this->formatExplanationAnswers($selected, $optionMap),
                    'correct_text' => $this->formatExplanationAnswers($question->answers, $optionMap),
                    'explanation' => $question->explanation ?? '',
                    'marks' => $isCorrect ? $weight : 0.0
                ];
            }
        } else {
            foreach ($answers as $questionId => $userAnswer) {
                $question = $questionMap->get($questionId);
                if (!$question) continue;

                $weight = (float)($question->marks ?: 1);
                $isCorrect = $this->isAnswerCorrect($userAnswer, $question->answers, $question, $skipUnmapping ? '' : $shuffleSeed);

                if ($isCorrect) {
                    $correctCount++;
                    $earnedMarks += $weight;
                }
                $optionMap = $this->buildOptionMap($question, $shuffleSeed);
                $results[] = [
                    'question_id' => $questionId, 
                    'correct' => $isCorrect,
                    'selected' => $userAnswer,
                    'selected_text' => $this->formatExplanationAnswers($userAnswer, $optionMap),
                    'correct_text' => $this->formatExplanationAnswers($question->answers, $optionMap),
                    'explanation' => $question->explanation ?? '',
                    'marks' => $isCorrect ? $weight : 0.0
                ];
            }
        }

        $score = $totalPossibleMarks > 0 ? round(($earnedMarks / $totalPossibleMarks) * 100, 1) : 0.0;

        return [
            'results' => $results,
            'correct_count' => $correctCount,
            'earned_marks' => $earnedMarks,
            'total_possible_marks' => $totalPossibleMarks,
            'score' => $score,
        ];
    }

    /**
     * Reverses a shuffle based on a seed.
     */
    public function unmapShuffledAnswer(mixed $given, object $question, string $shuffleSeed): mixed
    {
        if (!$shuffleSeed || $shuffleSeed === '') {
            return $given;
        }

        $options = $question->options;
        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }
        if (empty($options)) {
            return $given;
        }

        $shuffled = $this->seededShuffle($options, $shuffleSeed . '::' . $question->id);
        
        if (is_array($given)) {
            $mapped = [];
            foreach ($given as $g) {
                if (is_numeric($g) && isset($shuffled[(int)$g])) {
                    $opt = $shuffled[(int)$g];
                    $mapped[] = is_array($opt) ? ($opt['id'] ?? $opt['text'] ?? $opt['body'] ?? $opt) : $opt;
                } else {
                    $mapped[] = $g;
                }
            }
            return $mapped;
        }

        if (is_numeric($given) && isset($shuffled[(int)$given])) {
            $opt = $shuffled[(int)$given];
            return is_array($opt) ? ($opt['id'] ?? $opt['text'] ?? $opt['body'] ?? $opt) : $opt;
        }

        return $given;
    }

    /**
     * Deterministic shuffle using a seed.
     */
    public function seededShuffle(array $items, string $seed): array
    {
        return $this->baseSeededShuffle($items, $seed);
    }

}
