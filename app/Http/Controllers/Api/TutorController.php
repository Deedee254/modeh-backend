<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subject;
use Illuminate\Http\Request;

class quiz-masterController extends Controller
{
    /**
     * Display a listing of public quiz-master profiles.
     */
    public function index()
    {
        // Get users who have a quiz-master profile, eager-load it, and paginate.
        $quiz-masters = User::whereHas('quiz-masterProfile')->with('quiz-masterProfile')->paginate(12);

        // Transform the collection for the frontend.
        $quiz-masters->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->social_avatar,
                'headline' => $user->quiz-masterProfile->headline ?? 'Experienced quiz-master',
            ];
        });

        return response()->json($quiz-masters);
    }

    /**
     * Display a single public quiz-master profile.
     */
    public function show(string $id)
    {
        // Find the user and eager-load all necessary relationships.
        $user = User::with(['quiz-masterProfile', 'quizzes.topic'])->findOrFail($id);

        // Ensure the user has a quiz-master profile.
        if (!$user->quiz-masterProfile) {
            return response()->json(['message' => 'quiz-master not found'], 404);
        }

        // The 'subjects' field on the quiz-master profile is a JSON array of subject IDs.
        // We need to fetch the full subject models for the frontend.
        $subjectIds = $user->quiz-masterProfile->subjects ?? [];
        $subjects = Subject::whereIn('id', $subjectIds)->get(['id', 'name']);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->social_avatar,
            'headline' => $user->quiz-masterProfile->headline ?? 'Experienced quiz-master',
            'bio' => $user->quiz-masterProfile->bio,
            'subjects' => $subjects, // Now an array of objects
            'quizzes' => $user->quizzes->map(function ($quiz) {
                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'topic_name' => $quiz->topic->name ?? null,
                ];
            }),
        ]]);
    }
}
