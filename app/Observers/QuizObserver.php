<?php

namespace App\Observers;

use App\Models\Quiz;
use Illuminate\Support\Facades\Log;

class QuizObserver
{
    /**
     * Handle the Quiz "saved" event.
     */
    public function saved(Quiz $quiz): void
    {
        // Propagate topic/subject/grade information to questions attached to this quiz
        try {
            $topic = $quiz->topic;
            $subjectId = $topic->subject_id ?? null;
            $topicId = $quiz->topic_id ?? null;
            $gradeId = $topic->grade_id ?? null;

            $quiz->questions()->where(function($q) use ($subjectId, $topicId, $gradeId) {
                $q->whereNull('subject_id')->orWhere('subject_id', '!=', $subjectId)
                  ->orWhereNull('topic_id')->orWhere('topic_id', '!=', $topicId)
                  ->orWhereNull('grade_id')->orWhere('grade_id', '!=', $gradeId);
            })->update([
                'subject_id' => $subjectId,
                'topic_id' => $topicId,
                'grade_id' => $gradeId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('QuizObserver failed to sync question metadata: ' . $e->getMessage());
        }
    }
}
