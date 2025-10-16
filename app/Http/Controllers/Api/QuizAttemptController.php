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

        // Load questions but strip the correct answers
        $quiz->load(['topic', 'questions']);
        $questions = $quiz->questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'body' => $q->body,
                'options' => $q->options,
                'media_path' => $q->media_path,
            ];
        });

        return response()->json(['quiz' => [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'timer_seconds' => $quiz->timer_seconds,
            'questions' => $questions,
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
            'answers' => 'nullable|array'
        ]);

        $answers = $payload['answers'] ?? [];

    $results = [];
    $correct = 0;
        foreach ($answers as $a) {
            $qid = intval($a['question_id'] ?? 0);
            $selected = $a['selected'] ?? null;
            $q = Question::find($qid);
            if (!$q) continue;

            $isCorrect = false;
            // assume $q->answers contains array of correct options
            $correctAnswers = is_array($q->answers) ? $q->answers : json_decode($q->answers, true) ?? [];
            if (is_array($selected)) {
                // compare sets
                $isCorrect = array_values($selected) == array_values($correctAnswers);
            } else {
                $isCorrect = in_array($selected, $correctAnswers);
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
                $attempt = QuizAttempt::create([
                    'user_id' => $user->id,
                    'quiz_id' => $quiz->id,
                    'answers' => $answers,
                    'score' => null,
                    'points_earned' => null,
                ]);
            } else {
                // simple points calculation: scale score by number of attempted questions (each attempted question worth 10 points)
                $pointsEarned = round(($score / 100) * ($attempted * 10), 2);

                $attempt = QuizAttempt::create([
                    'user_id' => $user->id,
                    'quiz_id' => $quiz->id,
                    'answers' => $answers,
                    'score' => $score,
                    'points_earned' => $pointsEarned,
                ]);

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
        if ($attempt && !$defer) {
            $this->achievementService->checkStreakAchievements($user, $request->input('streak', 0), $attempt->id ?? null);
            $this->achievementService->checkScoreAchievements($user, $score, $attempt->id ?? null);
            $this->achievementService->checkCompletionAchievements(
                $user,
                100 * (count($answers) / $quiz->questions()->count()),
                $attempt->id ?? null
            );
        }

        // Return attempt id (if created) and details. If attempt creation failed, return 500 so client knows to retry.
        if (!$attempt) {
            return response()->json(['ok' => false, 'message' => 'Failed to persist attempt'], 500);
        }

        return response()->json(['ok' => true, 'results' => $results, 'score' => $defer ? null : $score, 'attempt_id' => $attempt->id ?? null, 'points_delta' => $attempt->points_earned ?? 0, 'deferred' => $defer]);
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
            if (is_array($selected)) {
                $isCorrect = array_values($selected) == array_values($correctAnswers);
            } else {
                $isCorrect = in_array($selected, $correctAnswers);
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
            $this->achievementService->checkStreakAchievements($user, $request->input('streak', 0), $attempt->id ?? null);
            $this->achievementService->checkScoreAchievements($user, $score, $attempt->id ?? null);
            $this->achievementService->checkCompletionAchievements(
                $user,
                100 * (count($answers) / $quiz->questions()->count()),
                $attempt->id ?? null
            );

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
                'provided' => $provided,
                'correct' => $isCorrect,
                'correct_answers' => $correctAnswers,
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
}
