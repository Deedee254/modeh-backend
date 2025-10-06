<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ApprovalRequestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // Generic approval request handler: determines model by resource name
    public function store(Request $request, $resource, $id)
    {
        $user = $request->user();

        $map = [
            'topics' => \App\Models\Topic::class,
            'subjects' => \App\Models\Subject::class,
            'quizzes' => \App\Models\Quiz::class,
            'questions' => \App\Models\Question::class,
        ];

        if (!isset($map[$resource])) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $modelClass = $map[$resource];
        $item = $modelClass::find($id);
        if (!$item) return response()->json(['message' => 'Not found'], 404);

        // only owner or admin can request
        if (!isset($user->is_admin) || !$user->is_admin) {
            if ($item->created_by !== $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $item->approval_requested_at = now();
        $item->save();

        return response()->json(['message' => 'Approval requested', 'item' => $item]);
    }
}
