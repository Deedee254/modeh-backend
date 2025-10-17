<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Subject;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Update the quiz master's profile.
     */
    public function updateQuizMasterProfile(Request $request)
    {
        $user = $request->user();
        
        if ($user->role !== 'quiz-master' || !$user->quizMasterProfile) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'institution' => 'nullable|string',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'headline' => 'nullable|string',
            'bio' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->quizMasterProfile;
        $profile->update($request->only([
            'institution',
            'grade_id',
            'subjects',
            'headline',
            'bio',
        ]));

        // Return updated profile with relationships
        $user->load(['quizMasterProfile.grade']);
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->social_avatar,
                'headline' => $profile->headline ?? 'Experienced quiz master',
                'bio' => $profile->bio,
                'institution' => $profile->institution,
                'grade' => $profile->grade ? [
                    'id' => $profile->grade->id,
                    'name' => $profile->grade->name,
                ] : null,
                'subjects' => Subject::whereIn('id', $profile->subjects ?? [])->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name,
                        ];
                    }),
            ]
        ]);
    }

    /**
     * Update the quizee's profile.
     */
    public function updateQuizeeProfile(Request $request)
    {
        $user = $request->user();
        
        if ($user->role !== 'quizee' || !$user->quizeeProfile) {
            return response()->json(['message' => 'Not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'institution' => 'nullable|string',
            'grade_id' => 'nullable|exists:grades,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->quizeeProfile;
        $profile->update($request->only([
            'institution',
            'grade_id',
            'subjects',
            'first_name',
            'last_name',
        ]));

        // Return updated profile with relationships
        $user->load(['quizeeProfile.grade']);
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->social_avatar,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'institution' => $profile->institution,
                'grade' => $profile->grade ? [
                    'id' => $profile->grade->id,
                    'name' => $profile->grade->name,
                ] : null,
                'subjects' => Subject::whereIn('id', $profile->subjects ?? [])->get()
                    ->map(function ($subject) {
                        return [
                            'id' => $subject->id,
                            'name' => $subject->name,
                        ];
                    }),
            ]
        ]);
    }
}