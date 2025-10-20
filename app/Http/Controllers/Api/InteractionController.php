<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\QuizMaster;
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

        DB::transaction(function () use ($quiz, $user) {
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

                // Broadcast event for listeners
                Event::dispatch(new QuizLiked($quiz, $user));
            }
        });

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

        DB::transaction(function () use ($quizMaster, $user) {
            $exists = DB::table('quiz_master_follows')->where(['quiz_master_id' => $quizMaster->id, 'user_id' => $user->id])->exists();
            if (! $exists) {
                DB::table('quiz_master_follows')->insert([
                    'quiz_master_id' => $quizMaster->id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('quiz_masters')->where('id', $quizMaster->id)->increment('followers_count', 1);

                Event::dispatch(new QuizMasterFollowed($quizMaster, $user));
            }
        });

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
}
