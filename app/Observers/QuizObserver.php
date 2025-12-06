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
        // Propagate taxonomy information to questions attached to this quiz
        // Use quiz's direct IDs as source of truth, not topic nested values
        try {
            $levelId = $quiz->level_id ?? null;
            $gradeId = $quiz->grade_id ?? null;
            $subjectId = $quiz->subject_id ?? null;
            $topicId = $quiz->topic_id ?? null;

            $quiz->questions()->where(function($q) use ($levelId, $gradeId, $subjectId, $topicId) {
                $q->where(function($inner) use ($levelId, $gradeId, $subjectId, $topicId) {
                    $inner->whereNull('level_id')->orWhere('level_id', '!=', $levelId)
                      ->orWhereNull('grade_id')->orWhere('grade_id', '!=', $gradeId)
                      ->orWhereNull('subject_id')->orWhere('subject_id', '!=', $subjectId)
                      ->orWhereNull('topic_id')->orWhere('topic_id', '!=', $topicId);
                });
            })->update([
                'level_id' => $levelId,
                'grade_id' => $gradeId,
                'subject_id' => $subjectId,
                'topic_id' => $topicId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('QuizObserver failed to sync question metadata: ' . $e->getMessage());
        }
    }
}
