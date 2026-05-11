<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * Return a list of notifications (stubbed)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['notifications' => []], Response::HTTP_UNAUTHORIZED);
        }

        // Return recent notifications for the authenticated user in a lightweight shape
        $notifications = $user->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(function ($n) {
                return [
                    'id' => $n->id,
                    'type' => class_basename($n->type),
                    'data' => $n->data,
                    'title' => $n->data['title'] ?? ($n->data['subject'] ?? null),
                    'body' => $n->data['body'] ?? ($n->data['message'] ?? null),
                    'read' => $n->read_at !== null,
                    'created_at' => $n->created_at ? $n->created_at->toDateTimeString() : null,
                ];
            });

        return response()->json(['notifications' => $notifications]);
    }

    /**
     * Mark a notification as read (stub)
     */
    public function markRead(Request $request, $id)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $notification = $user->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found'], Response::HTTP_NOT_FOUND);
        }

        $notification->markAsRead();

        return response()->json(['success' => true, 'id' => $id]);
    }

    /**
     * Get various counts for sidebar badges
     */
    public function counts(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['unread_count' => 0]);

        $counts = [
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];

        // Admin counts
        if ($user->isAdmin()) {
            $counts['pending_quizzes'] = \App\Models\Quiz::where('is_approved', false)->whereNotNull('approval_requested_at')->count();
            $counts['flagged_questions'] = \App\Models\Question::whereHas('pendingFlags')->count();
            $counts['approvals_total'] = $counts['pending_quizzes'] + $counts['flagged_questions'];
        }

        // Quiz Master counts
        if ($user->role === 'quiz-master') {
            $counts['my_flagged_questions'] = \App\Models\Question::where('created_by', $user->id)
                ->whereHas('pendingFlags')
                ->count();
        }

        return response()->json($counts);
    }
}
