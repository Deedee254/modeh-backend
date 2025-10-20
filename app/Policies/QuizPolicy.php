<?php

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    /**
     * Determine whether the user can view analytics for the quiz.
     */
    public function viewAnalytics(User $user, Quiz $quiz)
    {
        // Owner (created_by or user_id) or admin
        if (($quiz->created_by && $quiz->created_by === $user->id) || ($quiz->user_id && $quiz->user_id === $user->id)) return true;
        if (property_exists($user, 'is_admin') && $user->is_admin) return true;
        return false;
    }

    /**
     * Basic update permission (owner or admin)
     */
    public function update(User $user, Quiz $quiz)
    {
        if (($quiz->created_by && $quiz->created_by === $user->id) || ($quiz->user_id && $quiz->user_id === $user->id)) return true;
        if (property_exists($user, 'is_admin') && $user->is_admin) return true;
        return false;
    }
}
