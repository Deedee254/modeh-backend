<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuestionFlagController extends Controller
{
    /**
     * Flag a question
     */
    public function store(Request $request, Question $question)
    {
        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $flag = QuestionFlag::create([
            'question_id' => $question->id,
            'user_id' => Auth::id(),
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        // Threshold logic:
        $pendingFlagsCount = $question->pendingFlags()->count();

        if ($pendingFlagsCount >= 3) {
            $question->update(['is_approved' => false]);
            
            // Optionally notify quiz master
            // Notification::send($question->author, new QuestionAutoUnapproved($question));
        }

        return response()->json([
            'message' => 'Question flagged successfully.',
            'flag' => $flag,
            'auto_unapproved' => $pendingFlagsCount >= 3
        ]);
    }

    /**
     * List flags for a question (Admin only)
     */
    public function index(Question $question)
    {
        // Admin middleware should be applied in routes
        $flags = $question->flags()->with('user:id,name,email')->latest()->get();

        return response()->json([
            'flags' => $flags
        ]);
    }
}
