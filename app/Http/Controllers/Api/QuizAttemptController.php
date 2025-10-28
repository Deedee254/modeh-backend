<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Services\AchievementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuizAttemptController extends Controller
{
    protected $achievementService;

    public function __construct(AchievementService $achievementService) 
    {
        $this->achievementService = $achievementService;
    }
    public function show(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        // If the requester is authenticated and is the owner (created_by or user_id) or an admin,
        // return the full quiz with relations so the quiz-master UI can display metadata.
        if ($user) {
            $isOwner = false;
            try {
                $isOwner = ($quiz->created_by && (string)$quiz->created_by === (string)$user->id) || ($quiz->user_id && (string)$quiz->user_id === (string)$user->id);
            } catch (\Exception $e) {
                $isOwner = false;
            }
            if ($isOwner || ($user->is_admin ?? false)) {
                // Ensure the grade->level relation is loaded so the frontend can
                // reconstruct level_id/level metadata when pre-filling the create form.
                $quiz->load(['topic.subject', 'subject', 'grade.level', 'questions']);
                return response()->json(['quiz' => $quiz]);
            }
        }

        // Public / attempt view: Load questions and taxonomy so the frontend can display details
        $quiz->load(['topic.subject', 'subject', 'grade.level', 'questions']);
        $questions = $quiz->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'body' => $q->body,
                'options' => $q->options,
                'media_path' => $q->media_path,
            ];
        });

        // expose taxonomy objects in the public payload (level may be nested under grade)
        $level = $quiz->level ?? ($quiz->grade && $quiz->grade->level ? $quiz->grade->level : null);

        return response()->json(['quiz' => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'timer_seconds' => $quiz->timer_seconds,
            // Expose multimedia fields publicly as requested
            'youtube_url' => $quiz->youtube_url ?? null,
            'cover_image' => $quiz->cover_image ?? null,
            'questions' => $questions,
            'topic' => $quiz->topic ?? null,
            'subject' => $quiz->subject ?? null,
            'grade' => $quiz->grade ?? null,
            'level_id' => $quiz->level_id ?? ($level ? ($level->id ?? null) : null),
            'level' => $level ?? null,
        ]]);
    }

    public function submit(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);
        }

        // allow missing or partial answers (accept empty submissions)
        $payload = $request->validate([
            'answers' => 'nullable|array',
            'question_times' => 'nullable|array',
            'total_time_seconds' => 'nullable|numeric',
            'started_at' => 'nullable|date',
            'attempt_id' => 'nullable|integer',
        ]);

        $answers = $payload['answers'] ?? [];
    $questionTimes = $payload['question_times'] ?? null;
    $totalTimeSeconds = isset($payload['total_time_seconds']) ? (int)$payload['total_time_seconds'] : null;
    $attemptId = $payload['attempt_id'] ?? null;

    $results = [];
    $correct = 0;
        foreach ($answers as $a) {
            $qid = intval($a['question_id'] ?? 0);
            $selected = $a['selected'] ?? null;
            $q = Question::find($qid);
            if (!$q) continue;

            $isCorrect = false;
            // normalize both stored correct answers and submitted answers
            $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
            
            // handle objects or ids by extracting body text
            $normalizeValue = function($val) use ($q) {
                if (is_array($val) && isset($val['body'])) return $val['body'];
                if (is_array($val) && isset($val['text'])) return $val['text'];
                return (string)$val;
            };
            
            // normalize array of values
            $normalizeArray = function($arr) use ($normalizeValue) {
                $normalized = array_map($normalizeValue, $arr);
                $normalized = array_map('trim', $normalized);
                $normalized = array_map('strtolower', $normalized);
                sort($normalized);
                return $normalized;
            };
            
            if (is_array($selected)) {
                // normalize and sort both arrays for order-insensitive comparison
                $submittedAnswers = $normalizeArray($selected);
                $correctAnswers = $normalizeArray($correctAnswers);
                $isCorrect = $submittedAnswers == $correctAnswers;
            } else {
                // normalize single answer
                $submittedAnswer = strtolower(trim($normalizeValue($selected)));
                $correctAnswers = $normalizeArray($correctAnswers);
                $isCorrect = in_array($submittedAnswer, $correctAnswers);
            }

            if ($isCorrect) $correct++;
            $results[] = ['question_id' => $qid, 'correct' => $isCorrect];
        }

        // Compute score relative to attempted questions (so skipped questions are ignored in scoring)
        $attempted = max(0, count($answers));
        if ($attempted > 0) {
            $score = round($correct / $attempted * 100, 1);
        } else {
            $score = 0;
        }

        // Allow submit to only persist answers and defer marking (score calculation, points, achievements)
        $defer = $request->boolean('defer_marking', false);

        // persist attempt
        try {
            DB::beginTransaction();

            if ($defer) {
                // persist attempt without scoring/points; marking will be performed later via markAttempt
                if ($attemptId) {
                    $attempt = QuizAttempt::where('id', $attemptId)->where('user_id', $user->id)->first();
                    if ($attempt) {
                        $attempt->answers = $answers;
                        $attempt->total_time_seconds = $totalTimeSeconds;
                        $attempt->per_question_time = $questionTimes;
                        $attempt->save();
                    }
                } else {
                    $attempt = QuizAttempt::create([
                        'user_id' => $user->id,
                        'quiz_id' => $quiz->id,
                        'answers' => $answers,
                        'score' => null,
                        'points_earned' => null,
                        'total_time_seconds' => $totalTimeSeconds,
                        'per_question_time' => $questionTimes,
                    ]);
                }
            } else {
                // simple points calculation: scale score by number of attempted questions (each attempted question worth 10 points)
                $pointsEarned = round(($score / 100) * ($attempted * 10), 2);

                if ($attemptId) {
                    $attempt = QuizAttempt::where('id', $attemptId)->where('user_id', $user->id)->first();
                    if ($attempt) {
                        $attempt->answers = $answers;
                        $attempt->score = $score;
                        $attempt->points_earned = $pointsEarned;
                        $attempt->total_time_seconds = $totalTimeSeconds;
                        $attempt->per_question_time = $questionTimes;
                        $attempt->save();
                    }
                } else {
                    $attempt = QuizAttempt::create([
                        'user_id' => $user->id,
                        'quiz_id' => $quiz->id,
                        'answers' => $answers,
                        'score' => $score,
                        'points_earned' => $pointsEarned,
                        'total_time_seconds' => $totalTimeSeconds,
                        'per_question_time' => $questionTimes,
                    ]);
                }

                // persist points to user atomically; don't let missing column break the attempt
                if ($attempt && method_exists($user, 'increment')) {
                    try {
                        $user->increment('points', $pointsEarned);
                    } catch (\Exception $e) {
                        // log and continue; some test DBs may not have a points column
                        Log::warning('Could not increment user points: ' . $e->getMessage());
                    }
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed saving quiz attempt: ' . $e->getMessage());
            $attempt = null;
        }

            // Check achievements only when marking occurred (not deferred)
        $awarded = [];
        if ($attempt && !$defer) {
            // Get previous attempt score for improvement check
            $previousAttempt = QuizAttempt::where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->where('id', '!=', $attempt->id)
                ->orderByDesc('created_at')
                ->first();
            
            $achievementPayload = [
                'type' => 'quiz',
                'score' => $score,
                'time' => $totalTimeSeconds,
                'question_count' => $quiz->questions()->count(),
                'attempt_id' => $attempt->id,
                'quiz_id' => $quiz->id,
                'subject_id' => $quiz->subject_id ?? null,
                'streak' => $request->input('streak', 0),
                'previous_score' => $previousAttempt ? $previousAttempt->score : null,
                'total' => 100 * (count($answers) / $quiz->questions()->count())
            ];

            try {
                $achievements = $this->achievementService->checkAchievements($user, $achievementPayload);
                if (is_array($achievements) && count($achievements)) {
                    $awarded = array_merge($awarded, $achievements);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to check achievements: ' . $e->getMessage());
            }
        }

        // Return attempt id (if created) and details. If attempt creation failed, return 500 so client knows to retry.
        if (!$attempt) {
            return response()->json(['ok' => false, 'message' => 'Failed to persist attempt'], 500);
        }

        $refreshedUser = $user->fresh()->load('achievements');
        return response()->json(['ok' => true, 'results' => $results, 'score' => $defer ? null : $score, 'attempt_id' => $attempt->id ?? null, 'points_delta' => $attempt->points_earned ?? 0, 'deferred' => $defer, 'awarded_achievements' => $awarded, 'user' => $refreshedUser]);
    }

    /**
     * Server-side attempt start: create a draft attempt with started_at controlled by server.
     * Returns attempt id which the client should include in subsequent submit calls.
     */
    public function startAttempt(Request $request, Quiz $quiz)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $payload = $request->validate([
            'meta' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        try {
            $attempt = QuizAttempt::create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'answers' => [],
                'score' => null,
                'points_earned' => null,
                'total_time_seconds' => null,
                'per_question_time' => null,
                'started_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed creating server-start attempt: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Failed to start attempt'], 500);
        }

        return response()->json(['ok' => true, 'attempt_id' => $attempt->id, 'started_at' => $attempt->started_at]);
    }

    /**
     * Mark a previously saved (possibly deferred) attempt and return enriched result.
     * Requires an active subscription before revealing results.
     */
    public function markAttempt(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        if ($attempt->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        // Subscription or one-off purchase check
        $activeSub = \App\Models\Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();

        $hasOneOff = \App\Models\OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'quiz')
            ->where('item_id', $attempt->quiz_id)
            ->where('status', 'confirmed')
            ->exists();

        if (!$activeSub && !$hasOneOff) {
            return response()->json(['ok' => false, 'message' => 'Subscription or one-off purchase required'], 403);
        }

        // Enforce package limits if present
        if ($activeSub && $activeSub->package && is_array($activeSub->package->features)) {
            $features = $activeSub->package->features;
            // limit key path: features.limits.quiz_results => integer allowed per day (or null = unlimited)
            $limit = $features['limits']['quiz_results'] ?? $features['limits']['results'] ?? null;
            if ($limit !== null) {
                // compute todays usage for this user (marked attempts revealed)
                $today = now()->startOfDay();
                $used = QuizAttempt::where('user_id', $request->user()->id)
                    ->whereNotNull('score')
                    ->where('created_at', '>=', $today)
                    ->count();
                if ($used >= intval($limit)) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'limit_reached',
                        'limit' => [
                            'type' => 'quiz_results',
                            'value' => intval($limit)
                        ],
                        'message' => 'Result access limit reached for your plan'
                    ], 403);
                }
            }
        }

        // Recompute score from stored answers
        $answers = $attempt->answers ?? [];
        $quiz = $attempt->quiz()->with('questions')->first();
        $correct = 0;
        $results = [];
        foreach ($answers as $a) {
            $qid = intval($a['question_id'] ?? 0);
            $selected = $a['selected'] ?? null;
            $q = $quiz->questions->firstWhere('id', $qid);
            if (!$q) continue;

            $isCorrect = false;
            $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];

            // Build option id -> body map to resolve numeric/ID answers to readable text
            $optionMap = [];
            if (is_array($q->options)) {
                foreach ($q->options as $opt) {
                    if (is_array($opt) && isset($opt['id'])) {
                        $optionMap[(string)$opt['id']] = $opt['body'] ?? $opt['text'] ?? null;
                    }
                }
            }

            // normalize values: extract body/text from objects, resolve option ids to bodies when possible
            $normalizeValue = function($val) use ($optionMap) {
                if (is_array($val) && isset($val['body'])) return $val['body'];
                if (is_array($val) && isset($val['text'])) return $val['text'];
                if (!is_array($val)) {
                    $key = (string)$val;
                    if ($key !== '' && isset($optionMap[$key]) && $optionMap[$key] !== null) return $optionMap[$key];
                }
                return (string)$val;
            };

            $normalizeArray = function($arr) use ($normalizeValue) {
                $normalized = array_map($normalizeValue, $arr ?: []);
                $normalized = array_map('trim', $normalized);
                $normalized = array_map('strtolower', $normalized);
                sort($normalized);
                return $normalized;
            };

            if (is_array($selected)) {
                $submittedAnswers = $normalizeArray($selected);
                $correctNormalized = $normalizeArray($correctAnswers);
                $isCorrect = $submittedAnswers == $correctNormalized;
            } else {
                $submittedAnswer = strtolower(trim($normalizeValue($selected)));
                $correctNormalized = $normalizeArray($correctAnswers);
                $isCorrect = in_array($submittedAnswer, $correctNormalized);
            }

            if ($isCorrect) $correct++;
            $results[] = ['question_id' => $qid, 'correct' => $isCorrect];
        }

        $attempted = max(0, count($answers));
        $score = $attempted > 0 ? round($correct / $attempted * 100, 1) : 0;
        $pointsEarned = round(($score / 100) * ($attempted * 10), 2);

        try {
            DB::beginTransaction();

            $attempt->score = $score;
            $attempt->points_earned = $pointsEarned;
            $attempt->save();

            // award points to user
            if (method_exists($user, 'increment')) {
                try {
                    $user->increment('points', $pointsEarned);
                } catch (\Exception $e) {
                    Log::warning('Could not increment user points on marking: ' . $e->getMessage());
                }
            }

            // achievements
            $awarded = [];
            $previousAttempt = QuizAttempt::where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->where('id', '!=', $attempt->id)
                ->orderByDesc('created_at')
                ->first();

            $achievementPayload = [
                'type' => 'quiz',
                'score' => $score,
                'time' => $attempt->total_time_seconds,
                'question_count' => $quiz->questions()->count(),
                'attempt_id' => $attempt->id,
                'quiz_id' => $quiz->id,
                'subject_id' => $quiz->subject_id ?? null,
                'streak' => $request->input('streak', 0),
                'previous_score' => $previousAttempt ? $previousAttempt->score : null,
                'total' => 100 * (count($answers) / $quiz->questions()->count())
            ];

            try {
                $achievements = $this->achievementService->checkAchievements($user, $achievementPayload);
                if (is_array($achievements) && count($achievements)) {
                    $awarded = array_merge($awarded, $achievements);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to check achievements: ' . $e->getMessage());
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed marking quiz attempt: ' . $e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Failed to mark attempt'], 500);
        }

        // Reuse showAttempt logic to build details and badges
        return $this->showAttempt($request, $attempt);
    }

    /**
     * Return a single QuizAttempt for the authenticated user with enriched data
     */
    public function showAttempt(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        // Subscription check
        $activeSub = \App\Models\Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function($q) {
                $now = now();
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderByDesc('started_at')
            ->first();
        if (!$activeSub) {
            return response()->json(['ok' => false, 'message' => 'Subscription required'], 403);
        }

        // enforce package limits (e.g. number of revealed results per day)
        if ($activeSub && $activeSub->package && is_array($activeSub->package->features)) {
            $features = $activeSub->package->features;
            $limit = $features['limits']['quiz_results'] ?? $features['limits']['results'] ?? null;
            if ($limit !== null) {
                $today = now()->startOfDay();
                $used = QuizAttempt::where('user_id', $user->id)
                    ->whereNotNull('score')
                    ->where('created_at', '>=', $today)
                    ->count();
                if ($used >= intval($limit)) {
                    return response()->json([
                        'ok' => false,
                        'code' => 'limit_reached',
                        'limit' => [
                            'type' => 'quiz_results',
                            'value' => intval($limit)
                        ],
                        'message' => 'Daily result reveal limit reached for your plan'
                    ], 403);
                }
            }
        }

        if ($attempt->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $quiz = $attempt->quiz()->with('questions')->first();

        // Build per-question correctness info (answers stored on attempt)
        $answers = $attempt->answers ?? [];
        $details = [];
        foreach ($quiz->questions as $q) {
            $provided = null;
            foreach ($answers as $a) {
                if ((int)($a['question_id'] ?? 0) === (int)$q->id) { $provided = $a['selected'] ?? null; break; }
            }

            $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];

            // Build a simple option id -> body map to resolve numeric/ID answers to readable text
            $optionMap = [];
            if (is_array($q->options)) {
                foreach ($q->options as $opt) {
                    if (is_array($opt) && isset($opt['id'])) {
                        $optionMap[(string)$opt['id']] = $opt['body'] ?? $opt['text'] ?? null;
                    }
                }
            }

            // normalize to readable text with option-id resolution when possible
            $normalizeToText = function($val) use ($optionMap) {
                if (is_array($val) && isset($val['body'])) return $val['body'];
                if (is_array($val) && isset($val['text'])) return $val['text'];
                // if scalar and matches an option id, return the option body
                if (!is_array($val)) {
                    $key = (string)$val;
                    if ($key !== '' && isset($optionMap[$key]) && $optionMap[$key] !== null) return $optionMap[$key];
                }
                return (string)$val;
            };

            $isCorrect = false;
            if (is_array($provided)) {
                $isCorrect = array_values($provided) == array_values($correctAnswers);
            } else {
                $isCorrect = in_array($provided, $correctAnswers);
            }

            $details[] = [
                'question_id' => $q->id,
                'body' => $q->body,
                'options' => $q->options,
                'provided' => is_array($provided) ? array_map($normalizeToText, $provided) : $normalizeToText($provided),
                'correct' => $isCorrect,
                'correct_answers' => array_map($normalizeToText, $correctAnswers),
                'explanation' => $q->explanation ?? null,
            ];
        }

        // Gather badges earned by this attempt only
        $badges = [];
        if (method_exists($user, 'badges')) {
            $badges = $user->badges()
                ->wherePivot('attempt_id', $attempt->id)
                ->latest('user_badges.created_at')
                ->get()
                ->map(function ($b) {
                    return ['id' => $b->id, 'title' => $b->name ?? $b->title ?? null, 'description' => $b->description, 'earned_at' => $b->pivot->earned_at ?? null];
                });
        }

        // Calculate rank for this quiz
        $rank = null;
        $totalParticipants = 0;
        if ($attempt->quiz_id) {
            $totalParticipants = QuizAttempt::where('quiz_id', $attempt->quiz_id)
                ->whereNotNull('score')
                ->distinct('user_id')
                ->count('user_id');

            $higherScores = QuizAttempt::where('quiz_id', $attempt->quiz_id)
                ->where('score', '>', $attempt->score)->distinct('user_id')->count('user_id');
            $rank = $higherScores + 1;
        }

        return response()->json([
            'ok' => true,
            'attempt' => [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'score' => $attempt->score,
                'points_earned' => $attempt->points_earned ?? 0,
                'total_time_seconds' => $attempt->total_time_seconds ?? null,
                'per_question_time' => $attempt->per_question_time ?? null,
                'details' => $details,
                'created_at' => $attempt->created_at,
            ],
            'badges' => $badges,
            'points' => $user->points ?? 0,
            'rank' => $rank,
            'total_participants' => $totalParticipants,
        ]);
    }

    /**
     * Return only the raw attempt details for the owner so they can review answers prior to purchase/subscription.
     * This endpoint does NOT require an active subscription but does require authentication and ownership.
     */
    public function reviewAttempt(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        if ($attempt->user_id !== $user->id) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        // Return the attempt answers and any stored details without revealing computed scores or other protected data
        return response()->json([
            'ok' => true,
            'attempt' => [
                'id' => $attempt->id,
                'quiz_id' => $attempt->quiz_id,
                'answers' => $attempt->answers ?? [],
                'created_at' => $attempt->created_at,
            ]
        ]);
    }

    /**
     * List authenticated user's quiz attempts (paginated)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $perPage = max(1, (int)$request->get('per_page', 10));
        $q = QuizAttempt::query()->where('user_id', $user->id)->orderBy('created_at', 'desc');
        $data = $q->paginate($perPage);

        // map attempts to a simple shape
        $data->getCollection()->transform(function ($a) {
            return [
                'id' => $a->id,
                'quiz_id' => $a->quiz_id,
                'score' => $a->score,
                'points_earned' => $a->points_earned ?? 0,
                'created_at' => $a->created_at,
            ];
        });

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * Return aggregated quiz stats for the authenticated user
     */
    public function getUserStats(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $attempts = QuizAttempt::where('user_id', $user->id)->whereNotNull('score')->get();

        $totalAttempts = $attempts->count();
        $averageScore = $totalAttempts ? round($attempts->avg('score'), 1) : 0;
        $totalTime = $attempts->sum('total_time_seconds') ?? 0;
        $avgQuizTime = $totalAttempts ? round($attempts->avg('total_time_seconds'), 2) : 0;
        $fastestQuizTime = $attempts->min('total_time_seconds') ?? 0;

        // average question time: compute per attempt if per_question_time exists
        $questionTimes = [];
        foreach ($attempts as $a) {
            $pqt = $a->per_question_time ?? null;
            if (is_array($pqt)) {
                $questionTimes = array_merge($questionTimes, $pqt);
            } elseif (is_string($pqt)) {
                // try decode
                $decoded = json_decode($pqt, true);
                if (is_array($decoded)) $questionTimes = array_merge($questionTimes, $decoded);
            }
        }
        $avgQuestionTime = count($questionTimes) ? round(array_sum($questionTimes) / count($questionTimes), 2) : 0;

        // points today
        $today = now()->startOfDay();
        $pointsToday = QuizAttempt::where('user_id', $user->id)
            ->where('created_at', '>=', $today)
            ->sum('points_earned');

        return response()->json([
            'ok' => true,
            'total_attempts' => $totalAttempts,
            'average_score' => $averageScore,
            'total_time_seconds' => $totalTime,
            'avg_quiz_time' => $avgQuizTime,
            'fastest_quiz_time' => $fastestQuizTime,
            'avg_question_time' => $avgQuestionTime,
            'points_today' => $pointsToday,
            'current_streak' => $user->current_streak ?? 0,
            'total_points' => $user->points ?? 0,
        ]);
    }
}
