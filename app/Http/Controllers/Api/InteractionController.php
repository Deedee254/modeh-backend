<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;
use App\Models\QuizMaster;
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

    public function followQuizMaster(Request $request, $quizMasterId)
    {
        $user = $request->user();
        DB::table('quiz_master_follows')->updateOrInsert(['quiz_master_id' => $quizMasterId, 'user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);
        return response()->json(['following' => true]);
    }

    public function unfollowQuizMaster(Request $request, $quizMasterId)
    {
        $user = $request->user();
        DB::table('quiz_master_follows')->where(['quiz_master_id' => $quizMasterId, 'user_id' => $user->id])->delete();
        return response()->json(['following' => false]);
    }
}
