<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\QuizMaster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Events\QuizLiked;
use App\Events\QuizMasterFollowed;

class InteractionController extends Controller
{
    // Like a quiz (idempotent)
    public function likeQuiz(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        // Perform DB work inside a transaction and return whether we should broadcast
        $shouldBroadcastLike = DB::transaction(function () use ($quiz, $user) {
            $exists = DB::table('quiz_likes')->where(['quiz_id' => $quiz->id, 'user_id' => $user->id])->exists();
            if (! $exists) {
                DB::table('quiz_likes')->insert([
                    'quiz_id' => $quiz->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Atomically increment cached counter on quizzes table
                DB::table('quizzes')->where('id', $quiz->id)->increment('likes_count', 1);

                // indicate that we should broadcast
                return true;
            }

            return false;
        });

        if ($shouldBroadcastLike) {
            try {
                Event::dispatch(new QuizLiked($quiz, $user));
            } catch (\Throwable $e) {
                \Log::error('Failed to broadcast QuizLiked event', [
                    'quiz_id' => $quiz->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['liked' => true]);
    }

    public function unlikeQuiz(Request $request, Quiz $quiz)
    {
        $user = $request->user();

        DB::transaction(function () use ($quiz, $user) {
            $deleted = DB::table('quiz_likes')->where(['quiz_id' => $quiz->id, 'user_id' => $user->id])->delete();
            if ($deleted) {
                DB::table('quizzes')->where('id', $quiz->id)->decrement('likes_count', 1);
            }
        });

        return response()->json(['liked' => false]);
    }

    public function followQuizMaster(Request $request, QuizMaster $quizMaster)
    {
        $user = $request->user();

        // Use a flag to indicate whether we should broadcast after the DB commit.
        $shouldBroadcast = false;

        DB::transaction(function () use ($quizMaster, $user, &$shouldBroadcast) {
            $exists = DB::table('quiz_master_follows')->where(['quiz_master_id' => $quizMaster->id, 'user_id' => $user->id])->exists();
            if (! $exists) {
                DB::table('quiz_master_follows')->insert([
                    'quiz_master_id' => $quizMaster->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('quiz_masters')->where('id', $quizMaster->id)->increment('followers_count', 1);

                // Mark that we should broadcast after the transaction commits.
                $shouldBroadcast = true;
            }
        });

        // Dispatch broadcast after the transaction has committed. If broadcasting
        // fails (e.g. Echo/Push service unavailable) we log the error but do not
        // roll back the DB changes.
        if ($shouldBroadcast) {
            try {
                Event::dispatch(new QuizMasterFollowed($quizMaster, $user));
            } catch (\Throwable $e) {
                // Don't let broadcast failures break the follow operation.
                // Log for later inspection.
                \Log::error('Failed to broadcast QuizMasterFollowed event', [
                    'quiz_master_id' => $quizMaster->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['following' => true]);
    }

    public function unfollowQuizMaster(Request $request, QuizMaster $quizMaster)
    {
        $user = $request->user();

        DB::transaction(function () use ($quizMaster, $user) {
            $deleted = DB::table('quiz_master_follows')->where(['quiz_master_id' => $quizMaster->id, 'user_id' => $user->id])->delete();
            if ($deleted) {
                DB::table('quiz_masters')->where('id', $quizMaster->id)->decrement('followers_count', 1);
            }
        });

        return response()->json(['following' => false]);
    }

    /**
     * Return users who follow the authenticated quiz-master and users who
     * have liked any quiz created by the authenticated quiz-master.
     * Minimal payload: id, name, email, avatar
     */
    public function quizMasterFollowers(Request $request)
    {
        $user = $request->user();

        if (! $user || $user->role !== 'quiz-master') {
            return response()->json(['followers' => []]);
        }

        // IDs of users who explicitly follow this quiz-master
        $followerIds = DB::table('quiz_master_follows')
            ->where('quiz_master_id', $user->id)
            ->pluck('user_id')
            ->toArray();

        // IDs of users who liked any quizzes belonging to this quiz-master
        $quizIds = DB::table('quizzes')
            ->where('created_by', $user->id)
            ->pluck('id')
            ->toArray();

        $likerIds = [];
        if (! empty($quizIds)) {
            $likerIds = DB::table('quiz_likes')
                ->whereIn('quiz_id', $quizIds)
                ->pluck('user_id')
                ->toArray();
        }

        // Build follower user objects
        $followers = [];
        if (! empty($followerIds)) {
            $followers = User::whereIn('id', array_values(array_unique($followerIds)))
                ->get(['id', 'name', 'email', 'social_avatar', 'avatar_url'])
                ->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        // Prefer explicit uploaded avatar_url then fall back to social_avatar
                        'avatar' => $u->avatar_url ?? $u->social_avatar,
                    ];
                })->values();
        }

        // Build liker user objects
        $likers = [];
        if (! empty($likerIds)) {
            $likers = User::whereIn('id', array_values(array_unique($likerIds)))
                ->get(['id', 'name', 'email', 'social_avatar', 'avatar_url'])
                ->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'avatar' => $u->avatar_url ?? $u->social_avatar,
                    ];
                })->values();
        }

        return response()->json([
            'followers' => $followers,
            'likers' => $likers,
        ]);
    }

    /**
     * Get the quiz masters that the authenticated user is following
     * Returns paginated list of followed quiz masters with their basic info
     */
    public function userFollowing(Request $request)
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 8);

        $followedMasters = DB::table('quiz_master_follows')
            ->where('user_id', $user->id)
            ->join('users', 'quiz_master_follows.quiz_master_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.avatar_url',
                'users.social_avatar'
            )
            ->paginate($perPage);

        return response()->json($followedMasters);
    }

    /**
     * Get the quizzes that the authenticated user has liked
     * Returns paginated list of liked quizzes with their details
     */
    public function userLikedQuizzes(Request $request)
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 12);

        $likedQuizzes = DB::table('quiz_likes')
            ->where('quiz_likes.user_id', $user->id)
            ->join('quizzes', 'quiz_likes.quiz_id', '=', 'quizzes.id')
            ->join('users', 'quizzes.created_by', '=', 'users.id')
            ->select(
                'quizzes.id',
                'quizzes.title',
                'quizzes.description',
                'quizzes.questions_count',
                'quizzes.likes_count',
                'users.name as created_by_name',
                'quizzes.created_at'
            )
            ->paginate($perPage);

        return response()->json($likedQuizzes);
    }

    /**
     * Get users who have liked a specific quiz
     * Returns list of users who liked the quiz with their basic info
     */
    public function quizLikers(Request $request, Quiz $quiz)
    {
        $perPage = $request->input('per_page', 50);

        $likers = DB::table('quiz_likes')
            ->where('quiz_likes.quiz_id', $quiz->id)
            ->join('users', 'quiz_likes.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.avatar_url',
                'users.social_avatar',
                'quiz_likes.created_at as liked_at'
            )
            ->orderBy('quiz_likes.created_at', 'desc')
            ->paginate($perPage);

        return response()->json($likers);
    }
}
