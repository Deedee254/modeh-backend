<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\quiz-master;
use Illuminate\Support\Facades\DB;

class InteractionController extends Controller
{
    public function likeQuiz(Request $request, $quizId)
    {
        $user = $request->user();
        $quiz = Quiz::findOrFail($quizId);
        DB::table('quiz_likes')->updateOrInsert(['quiz_id' => $quiz->id, 'user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);
        return response()->json(['liked' => true]);
    }

    public function unlikeQuiz(Request $request, $quizId)
    {
        $user = $request->user();
        DB::table('quiz_likes')->where(['quiz_id' => $quizId, 'user_id' => $user->id])->delete();
        return response()->json(['liked' => false]);
    }

    public function followquiz-master(Request $request, $quiz-masterId)
    {
        $user = $request->user();
        DB::table('quiz-master_follows')->updateOrInsert(['quiz-master_id' => $quiz-masterId, 'user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);
        return response()->json(['following' => true]);
    }

    public function unfollowquiz-master(Request $request, $quiz-masterId)
    {
        $user = $request->user();
        DB::table('quiz-master_follows')->where(['quiz-master_id' => $quiz-masterId, 'user_id' => $user->id])->delete();
        return response()->json(['following' => false]);
    }
}
