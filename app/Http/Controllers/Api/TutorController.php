<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subject;
use Illuminate\Http\Request;

class TutorController extends Controller
{
    /**
     * Display a listing of public tutor profiles.
     */
    public function index()
    {
        // Get users who have a tutor profile, eager-load it, and paginate.
        $tutors = User::whereHas('tutorProfile')->with('tutorProfile')->paginate(12);

        // Transform the collection for the frontend.
        $tutors->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->social_avatar,
                'headline' => $user->tutorProfile->headline ?? 'Experienced Tutor',
            ];
        });

        return response()->json($tutors);
    }

    /**
     * Display a single public tutor profile.
     */
    public function show(string $id)
    {
        // Find the user and eager-load all necessary relationships.
        $user = User::with(['tutorProfile', 'quizzes.topic'])->findOrFail($id);

        // Ensure the user has a tutor profile.
        if (!$user->tutorProfile) {
            return response()->json(['message' => 'Tutor not found'], 404);
        }

        // The 'subjects' field on the tutor profile is a JSON array of subject IDs.
        // We need to fetch the full subject models for the frontend.
        $subjectIds = $user->tutorProfile->subjects ?? [];
        $subjects = Subject::whereIn('id', $subjectIds)->get(['id', 'name']);

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->social_avatar,
            'headline' => $user->tutorProfile->headline ?? 'Experienced Tutor',
            'bio' => $user->tutorProfile->bio,
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
