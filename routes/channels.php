<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Private channel for individual users. Only allow if the authenticated user id matches.
 */
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Private channel for groups. Only allow if the authenticated user is a member of the group.
 */
Broadcast::channel('group.{groupId}', function ($user, $groupId) {
    // Lazy-check membership via relationship; avoid heavy queries in high-volume apps
    try {
        return \App\Models\Group::where('id', $groupId)->whereHas('members', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })->exists();
    } catch (\Throwable $e) {
        Log::warning('Group channel auth error: ' . $e->getMessage());
        return false;
    }
});

/**
 * Private channel for quizzes. Allow both authenticated users and guests to subscribe to quiz updates.
 * Quiz events (like likes) are public information, so guests should be able to see them.
 */
Broadcast::channel('quiz.{quizId}', function ($user, $quizId) {
    // Allow anyone (authenticated or guest) to listen to quiz events (likes, updates, etc.)
    // Quiz events are public information
    return true;
});
