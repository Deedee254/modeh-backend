<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Subject;
use Illuminate\Http\Request;

class QuizMasterController extends Controller
{
    /**
     * Display a listing of public quiz master profiles.
     */
    public function index()
    {
        $request = request();
        
        // Start building the query for users with a quiz master profile
        $query = User::query()->whereHas('quizMasterProfile');

        // Apply filters based on request parameters
        $query->whereHas('quizMasterProfile', function ($q) use ($request) {
            if ($request->has('grade_id') && $request->grade_id) {
                $q->where('grade_id', $request->grade_id);
            }
            if ($request->has('subject_id') && $request->subject_id) {
                // Assumes 'subjects' is a JSON array of IDs in the profile
                $q->whereJsonContains('subjects', (int)$request->subject_id);
            }
        });

        $quizMasters = $query->with(['quizMasterProfile.grade'])->paginate(12);

        // Transform the collection for the frontend.
        $quizMasters->getCollection()->transform(function ($user) {
            $profile = $user->quizMasterProfile;
            $subjects = Subject::whereIn('id', $profile->subjects ?? [])->get()
                ->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                    ];
                });

            return [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->social_avatar,
                'headline' => $profile->headline ?: 'An experienced quiz master',
                'institution' => $profile->institution ?: '',
                'grade' => $profile->grade ? [
                    'id' => $profile->grade->id,
                    'name' => $profile->grade->name,
                ] : null,
                'subjects' => $subjects,
            ];
        });

        return response()->json($quizMasters);
    }

    /**
     * Display a single public quiz master profile.
     */
    public function show(Request $request, string $id)
    {
        // Find the user and eager-load all necessary relationships.
        $user = User::with(['quizMasterProfile', 'quizzes.topic'])->findOrFail($id);

        // Ensure the user has a quiz master profile.
        if (!$user->quizMasterProfile) {
            return response()->json(['message' => 'Quiz master not found'], 404);
        }

        $profile = $user->quizMasterProfile;
        $subjects = $profile->subjectModels->map(function ($subject) {
            return [
                'id' => $subject->id,
                'name' => $subject->name,
            ];
        });

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->social_avatar,
            'headline' => $profile->headline ?? 'An experienced quiz master',
            'bio' => $profile->bio,
            'institution' => $profile->institution ?? 'Independent Educator',
            'grade' => $profile->grade ? [
                'id' => $profile->grade->id,
                'name' => $profile->grade->name,
            ] : null,
            'subjects' => $subjects,
            'quizzes' => $user->quizzes->map(function ($quiz) {
                return [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'topic_name' => $quiz->topic->name ?? null,
                ];
            }),
        ];

        // Add is_following for authenticated users
        if ($request->user()) {
            $data['is_following'] = \DB::table('quiz_master_follows')
                ->where('quiz_master_id', $user->id)
                ->where('user_id', $request->user()->id)
                ->exists();
        }

        return response()->json(['data' => $data]);
    }
}
