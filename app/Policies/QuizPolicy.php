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
        $isOwner = ($quiz->created_by && (string)$quiz->created_by === (string)$user->id) || 
                   ($quiz->user_id && (string)$quiz->user_id === (string)$user->id);
        
        if ($isOwner || $user->isAdmin()) return true;
        
        return false;
    }

    /**
     * Basic update permission (owner or admin)
     */
    public function update(User $user, Quiz $quiz)
    {
        $isOwner = ($quiz->created_by && (string)$quiz->created_by === (string)$user->id) || 
                   ($quiz->user_id && (string)$quiz->user_id === (string)$user->id);
                   
        if ($isOwner || $user->isAdmin()) return true;
        
        return false;
    }
}
