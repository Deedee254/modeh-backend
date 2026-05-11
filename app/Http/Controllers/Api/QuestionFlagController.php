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
            
            // Notify quiz master about auto-unapproval
            if ($question->creator) {
                $question->creator->notify(new \App\Notifications\QuestionAutoUnapproved($question));
            }
        } else {
            // Notify quiz master about the flag
            if ($question->creator) {
                $question->creator->notify(new \App\Notifications\QuestionFlagged($question, $flag));
            }
        }

        // Also notify admins about the flag
        $admins = \App\Models\User::where('role', 'admin')->get();
        \Illuminate\Support\Facades\Notification::send($admins, new \App\Notifications\QuestionFlagged($question, $flag));

        return response()->json([
            'message' => 'Question flagged successfully.',
            'flag' => $flag,
            'auto_unapproved' => $pendingFlagsCount >= 3
        ]);
    }

    public function index(Question $question)
    {
        // Admin middleware should be applied in routes
        $flags = $question->flags()->with('user:id,name,email')->latest()->get();

        return response()->json([
            'flags' => $flags
        ]);
    }

    /**
     * Get recent flags across the platform or for current user
     */
    public function recent(Request $request)
    {
        $user = $request->user();
        $query = QuestionFlag::with(['question:id,body,quiz_id', 'user:id,name', 'question.quiz:id,title'])
            ->latest();

        if ($user->role === 'quiz-master') {
            $query->whereHas('question', function ($q) use ($user) {
                $q->where('created_by', $user->id);
            });
        } elseif (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $flags = $query->limit(10)->get();

        return response()->json([
            'flags' => $flags
        ]);
    }
}
