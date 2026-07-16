<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\GuestQuizAttempt;
use App\Models\OneOffPurchase;
use App\Models\GuestUnlockToken;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Storage;
use App\Services\QuestionMarkingService;

class GuestQuizController extends Controller
{
    protected QuestionMarkingService $markingService;

    public function __construct(QuestionMarkingService $markingService)
    {
        $this->markingService = $markingService;
    }
    /**
     * Get quiz questions for guest (excludes is_correct from options)
     * Only allow free quizzes
     *
     * @param  \App\Models\Quiz $quiz
     * @return \Illuminate\Http\JsonResponse
     */
    public function getQuestions(Request $request, Quiz $quiz)
    {
        // Guests can never take institutional quizzes.
        if ($quiz->is_institutional) {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'error' => 'This quiz requires authentication. Please login or register to continue.',
                    'code' => 'INSTITUTIONAL_QUIZ'
                ], 403);
            }
            $accessResult = \App\Services\QuizAccessService::checkAccess($quiz, $user);
            if (empty($accessResult['can_access'])) {
                return response()->json([
                    'error' => $accessResult['message'] ?? 'You do not have access to this institutional quiz.',
                    'code' => 'INSTITUTIONAL_QUIZ_ACCESS_DENIED'
                ], 403);
            }
        }

        // Load questions and taxonomy metadata
        $quiz->load(['questions', 'topic.subject', 'subject', 'grade.level']);

        // Prepare questions (apply shuffling if configured)
        $shuffleSeed = (string)$request->input('shuffle_seed', bin2hex(random_bytes(4)));
        $prepared = $quiz->getPreparedQuestions($shuffleSeed);

        // expose taxonomy objects in the public payload (level may be nested under grade)
        $level = $quiz->level ?? ($quiz->grade && $quiz->grade->level ? $quiz->grade->level : null);

        return response()->json([
            'quiz' => [
                'id' => $quiz->id,
                'slug' => $quiz->slug,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'timer_seconds' => $quiz->timer_seconds,
                'per_question_seconds' => $quiz->per_question_seconds,
                'use_per_question_timer' => (bool)$quiz->use_per_question_timer,
                'attempts_allowed' => $quiz->attempts_allowed,
                'shuffle_questions' => (bool)$quiz->shuffle_questions,
                'shuffle_answers' => (bool)$quiz->shuffle_answers,
                'shuffle_seed' => $shuffleSeed,
                'access' => $quiz->is_paid ? 'paywall' : 'free',
                'is_paid' => (bool)$quiz->is_paid,
                'one_off_price' => $quiz->one_off_price,
                'price' => $quiz->price,
                'questions' => $prepared,
                // Taxonomy Metadata (Human Readable)
                'topic_name' => $quiz->topic?->title ?? $quiz->topic?->name ?? null,
                'subject_name' => $quiz->subject?->name ?? $quiz->topic?->subject?->name,
                'grade_name' => $quiz->grade?->name,
                'level_name' => $level ? ($level->name === 'Tertiary' ? ($level->course_name ?? $level->name) : $level->name) : null,
                'level_id' => $quiz->level_id ?? $level?->id,
                'grade_id' => $quiz->grade_id,
                'subject_id' => $quiz->subject_id,
                'topic_id' => $quiz->topic_id,
            ]
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
        // Guests can never submit institutional quizzes.
        if ($quiz->is_institutional) {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'error' => 'This quiz requires authentication. Please login or register to continue.',
                    'code' => 'INSTITUTIONAL_QUIZ'
                ], 403);
            }
            $accessResult = \App\Services\QuizAccessService::checkAccess($quiz, $user);
            if (empty($accessResult['can_access'])) {
                return response()->json([
                    'error' => $accessResult['message'] ?? 'You do not have access to this institutional quiz.',
                    'code' => 'INSTITUTIONAL_QUIZ_ACCESS_DENIED'
                ], 403);
            }
        }

        // Validate submission
        try {
            $validated = $request->validate([
                'answers' => 'required|array',
                'answers.*.question_id' => 'required|integer|min:1',
                'answers.*.selected' => 'nullable',
                'time_taken' => 'nullable|integer|min:0',
                'guest_identifier' => 'required|string',
                'shuffle_seed' => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Log validation errors for debugging
            Log::warning('Guest quiz submission validation failed', [
                'quiz_id' => $quiz->id,
                'errors' => $e->errors(),
                'payload_sample' => $request->get('answers') ? array_slice($request->get('answers'), 0, 1) : null
            ]);
            throw $e;
        }

        // Load questions
        $questions = $quiz->questions()->get();

        // Calculate score using service (handles answer unmapping internally if shuffle_seed is provided)
        $shuffleSeed = $validated['shuffle_seed'] ?? null;
        $scoringResult = $this->markingService->calculateScore($validated['answers'], $questions, true, (string)$shuffleSeed);

        $price = $quiz->price;
        $requiresPayment = ((bool) $quiz->is_paid) || ($price > 0);
        
        // If the request is authenticated, check if the user has subscription/institutional access
        if ($user = auth('sanctum')->user()) {
            $accessResult = \App\Services\QuizAccessService::checkAccess($quiz, $user);
            if (!empty($accessResult['can_access']) && !empty($accessResult['is_free'])) {
                $requiresPayment = false;
            }
        }

        // Payment is per-attempt. New attempts are always locked if payment is required.
        $isUnlocked = !$requiresPayment;

        // Prepare minimal result payload for guests
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
            'price' => $price,
            'requires_payment' => $requiresPayment,
            'locked' => !$isUnlocked,
        ];

        // Persist guest attempt so unlocked results can be fetched after payment/login.
        $guestAttempt = GuestQuizAttempt::create([
            'quiz_id' => $quiz->id,
            'guest_identifier' => $validated['guest_identifier'],
            'score' => (int) round((float) $scoringResult['score']),
            'percentage' => (int) round((float) $scoringResult['score']),
            'correct_count' => (int) $correctCount,
            'incorrect_count' => (int) max(0, $totalQuestions - $correctCount),
            'skipped_count' => 0,
            'time_taken' => (int) ($validated['time_taken'] ?? 0),
            'results' => $scoringResult['results'] ?? [],
            'is_locked' => !$isUnlocked,
            'unlocked_at' => $isUnlocked ? now() : null,
        ]);

        if (!$isUnlocked) {
            // Do not expose detailed per-question marking before purchase.
            $result['results'] = [];
        } else {
            $result['results'] = $this->formatResultsWithExplanations($scoringResult['results'] ?? [], $questions);
        }

        return response()->json([
            'success' => true,
            'attempt_id' => $guestAttempt->id,
            'requires_payment' => $requiresPayment,
            'locked' => !$isUnlocked,
            'price' => $price,
            'attempt' => $result
        ]);
    }

    /**
     * Mark a single question for a guest (server-side) without exposing all answers.
     * Accepts: question_id, selected (id or array), guest_identifier (optional)
     */
    public function markQuestion(Request $request, Quiz $quiz)
    {
        if ($quiz->is_institutional) {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'error' => 'This quiz requires authentication. Please login or register to continue.',
                    'code' => 'INSTITUTIONAL_QUIZ'
                ], 403);
            }
            $accessResult = \App\Services\QuizAccessService::checkAccess($quiz, $user);
            if (empty($accessResult['can_access'])) {
                return response()->json([
                    'error' => $accessResult['message'] ?? 'You do not have access to this institutional quiz.',
                    'code' => 'INSTITUTIONAL_QUIZ_ACCESS_DENIED'
                ], 403);
            }
        }

        $validated = $request->validate([
            'question_id' => 'required|integer',
            'selected' => 'nullable',
            'shuffle_seed' => 'nullable|string',
        ]);

        $question = $quiz->questions()->find($validated['question_id']);
        if (!$question) {
            return response()->json(['error' => 'Question not found'], 404);
        }

        $selected = $validated['selected'];
        $shuffleSeed = $validated['shuffle_seed'] ?? '';

        // Unmap if shuffled
        if ($shuffleSeed !== '') {
            $selected = $this->markingService->unmapShuffledAnswer($selected, $question, $shuffleSeed);
        }

        $isCorrect = $this->markingService->isAnswerCorrect($selected, $question->answers, $question);
        $optionMap = $this->markingService->buildOptionMap($question);

        return response()->json([
            'correct' => (bool) $isCorrect,
            'explanation' => $question->explanation ?? null,
            'provided' => $this->markingService->formatExplanationAnswers($selected, $optionMap),
            'correct_answer' => $this->markingService->formatExplanationAnswers($question->answers, $optionMap),
        ]);
    }

    /**
     * Fetch a persisted guest attempt with payment-aware unlock checks.
     */
    public function showAttempt(Request $request, string $attemptId)
    {
        $attempt = GuestQuizAttempt::with('quiz')->find($attemptId);
        if (!$attempt || !$attempt->quiz) {
            return response()->json(['ok' => false, 'message' => 'Attempt not found'], 404);
        }

        $guestIdentifier = (string) $request->query('guest_identifier', '');
        $unlockToken = (string) $request->query('unlock_token', '');
        $price = $attempt->quiz->price;
        $requiresPayment = ((bool) $attempt->quiz->is_paid) || ($price > 0);
        
        if ($user = auth('sanctum')->user()) {
            $accessResult = \App\Services\QuizAccessService::checkAccess($attempt->quiz, $user);
            if (!empty($accessResult['can_access']) && !empty($accessResult['is_free'])) {
                $requiresPayment = false;
            }
        }

        $isUnlocked = !$requiresPayment || !$attempt->is_locked;

        if (!$isUnlocked) {
            $hasUnlockToken = false;
            if ($unlockToken !== '') {
                $token = GuestUnlockToken::where('token', $unlockToken)
                    ->where('item_type', 'quiz')
                    ->where('item_id', $attempt->quiz_id)
                    ->where('expires_at', '>', now())
                    ->first();
                $hasUnlockToken = (bool) $token;
            }

            if ($hasUnlockToken) {
                $attempt->is_locked = false;
                $attempt->unlocked_at = now();
                $attempt->save();
                $isUnlocked = true;
            }
        }

        $payload = [
            'quiz_id' => $attempt->quiz_id,
            'quiz_title' => $attempt->quiz->title,
            'quiz_slug' => $attempt->quiz->slug,
            'guest_identifier' => $attempt->guest_identifier,
            'score' => $attempt->score,
            'percentage' => $attempt->percentage,
            'correct_count' => $attempt->correct_count,
            'incorrect_count' => $attempt->incorrect_count,
            'total_questions' => $attempt->correct_count + $attempt->incorrect_count + $attempt->skipped_count,
            'time_taken' => $attempt->time_taken,
            'attempted_at' => optional($attempt->created_at)->toIso8601String(),
            'price' => $price,
            'one_off_price' => $attempt->quiz->one_off_price ?? null,
            'default_quiz_one_off_price' => (function () use ($attempt) {
                try {
                    $setting = \App\Models\PricingSetting::singleton();
                    return (float) ($setting->default_quiz_one_off_price ?? 0);
                } catch (\Throwable $_) {
                    return 0.0;
                }
            })(),
            'requires_payment' => $requiresPayment,
            'locked' => !$isUnlocked,
            'results' => [],
        ];

        if ($isUnlocked) {
            $stored = $attempt->results ?? [];
            $storedFirst = is_array($stored) && count($stored) ? $stored[0] : null;
            $alreadyDetailed = is_array($storedFirst) && (array_key_exists('question_body', $storedFirst) || array_key_exists('is_correct', $storedFirst));

            $questions = $attempt->quiz->questions()->get()->keyBy('id');

            $formattedResults = [];
            if ($alreadyDetailed) {
                foreach ($stored as $result) {
                    $qid = $result['question_id'] ?? null;
                    $q = $qid ? $questions->get($qid) : null;
                    if ($q) {
                        $mediaUrl = $q->media_path ? ((\Illuminate\Support\Str::startsWith($q->media_path, ['http://', 'https://', '/'])) ? $q->media_path : Storage::url($q->media_path)) : null;
                        $result['media_path'] = $q->media_path;
                        $result['media_url'] = $mediaUrl;
                        $result['media_type'] = $q->media_type;
                        $result['youtube_url'] = $q->youtube_url;
                        $result['question_body'] = $q->body ?? $q->text ?? $result['question_body'] ?? '';
                        $result['body'] = $q->body ?? $q->text ?? $result['body'] ?? '';
                        $result['correct'] = isset($result['is_correct']) ? $result['is_correct'] : (isset($result['correct']) ? $result['correct'] : false);
                        $result['is_correct'] = $result['correct'];
                        if (!isset($result['correct_answers']) && isset($result['correct_answer'])) {
                            $result['correct_answers'] = $result['correct_answer'];
                        }
                    }
                    $formattedResults[] = $result;
                }
            } else {
                $formattedResults = $this->formatResultsWithExplanations(is_array($stored) ? $stored : [], $attempt->quiz->questions()->get());
            }
            $payload['results'] = $formattedResults;
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => $attempt->id,
            'attempt' => $payload,
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
            if (!$question) continue;

            $optionMap = $this->markingService->buildOptionMap($question);
            $provided = $result['selected'] ?? null;
            $mediaUrl = $question->media_path ? ((\Illuminate\Support\Str::startsWith($question->media_path, ['http://', 'https://', '/'])) ? $question->media_path : Storage::url($question->media_path)) : null;
            $isCorrect = $this->markingService->isAnswerCorrect($provided, $question->answers, $question);

            $formatted[] = [
                'question_id' => $result['question_id'],
                'question_body' => $question->body ?? $question->text ?? '',
                'body' => $question->body ?? $question->text ?? '',
                'is_correct' => $isCorrect,
                'correct' => $isCorrect,
                'marks_earned' => $result['marks'] ?? (float)($question->marks ?: 1),
                'explanation' => $question->explanation ?? null,
                'provided' => $this->markingService->formatExplanationAnswers($provided, $optionMap),
                'correct_answer' => $this->markingService->formatExplanationAnswers($question->answers, $optionMap),
                'correct_answers' => $this->markingService->formatExplanationAnswers($question->answers, $optionMap),
                'media_path' => $question->media_path,
                'media_url' => $mediaUrl,
                'media_type' => $question->media_type,
                'youtube_url' => $question->youtube_url,
            ];
        }

        return $formatted;
    }

    /**
     * Format answer for display
     *
     * @param  mixed  $answer
     * @param  array  $optionMap
     * @return string
     */
    private function formatAnswer($answer, array $optionMap = []): string
    {
        return $this->markingService->formatExplanationAnswers($answer, $optionMap);
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

    /**
     * Get random questions for Quick Battle (homepage widget).
     * Exposes is_correct so the frontend can immediately score the guest teaser.
     */
    public function quickBattleQuestions(Request $request)
    {
        $limit = min((int) $request->input('limit', 5), 20);
        $topicId = $request->input('topic_id');
        $subjectId = $request->input('subject_id');
        $gradeId = $request->input('grade_id');
        $levelId = $request->input('level_id');

        $query = \App\Models\Question::with('options')
            ->where('is_approved', true);

        if ($topicId) {
            $query->where('topic_id', $topicId);
        } elseif ($subjectId) {
            $query->where('subject_id', $subjectId);
        } elseif ($gradeId) {
            $query->whereHas('topic.subject', function($q) use ($gradeId) {
                $q->where('grade_id', $gradeId);
            });
        } elseif ($levelId) {
            $query->whereHas('topic.subject.grade', function($q) use ($levelId) {
                $q->where('level_id', $levelId);
            });
        }

        $questions = $query->inRandomOrder()->limit($limit)->get();

        // If not enough approved questions, fall back to any random approved questions.
        // This keeps the quick-play widget usable even with sparse approval data.
        if ($questions->count() < $limit) {
            $fallback = \App\Models\Question::with('options')
                ->where('is_approved', true)
                ->whereNotIn('id', $questions->pluck('id'))
                ->inRandomOrder()
                ->limit(max(0, $limit - $questions->count()))
                ->get();
            $questions = $questions->concat($fallback);
        }

        if ($questions->isEmpty()) {
            return response()->json(['questions' => []]);
        }

        // Format to match frontend expectations
        $formatted = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'text' => $q->question_text ?? $q->text,
                'explanation' => $q->explanation,
                'options' => collect($q->options)->map(function ($opt) {
                    return [
                        'id' => $opt->id,
                        'text' => $opt->option_text ?? $opt->text,
                        'is_correct' => (bool) $opt->is_correct,
                    ];
                })
            ];
        });

        return response()->json([
            'questions' => $formatted
        ]);
    }
}
