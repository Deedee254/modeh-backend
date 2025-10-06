<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuizAttempt;
use App\Services\AchievementService;

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

        $payload = $request->validate([
            'answers' => 'required|array'
        ]);

        $answers = $payload['answers'];

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

        $score = count($answers) ? round($correct / count($answers) * 100, 1) : 0;

        // persist attempt
        try {
            // simple points calculation: scale score by number of questions
            $num = count($answers);
            $pointsEarned = round(($score / 100) * ($num * 10), 2); // e.g., each question worth 10 points scaled by accuracy

            $attempt = QuizAttempt::create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'answers' => $answers,
                'score' => $score,
                'points_earned' => $pointsEarned,
            ]);

            // persist points to user
            if ($attempt && method_exists($user, 'increment')) {
                $user->increment('points', $pointsEarned);
            }
        } catch (\Exception $e) {
            $attempt = null;
        }

        // Check achievements and associate any awarded achievements with this attempt
        if ($attempt) {
            $this->achievementService->checkStreakAchievements($user, $request->input('streak', 0), $attempt->id ?? null);
            $this->achievementService->checkScoreAchievements($user, $score, $attempt->id ?? null);
            $this->achievementService->checkCompletionAchievements(
                $user,
                100 * (count($answers) / $quiz->questions()->count()),
                $attempt->id ?? null
            );
        }

        return response()->json(['ok' => true, 'results' => $results, 'score' => $score, 'attempt_id' => $attempt?->id ?? null, 'points_delta' => $attempt?->points_earned ?? 0]);
    }

    /**
     * Return a single QuizAttempt for the authenticated user with enriched data
     */
    public function showAttempt(Request $request, QuizAttempt $attempt)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

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
