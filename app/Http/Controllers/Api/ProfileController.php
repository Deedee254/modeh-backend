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
            'level_id' => 'nullable|exists:levels,id',
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
            'level_id',
            'subjects',
            'headline',
            'bio',
        ]));

        // If a grade was provided, also persist the associated level on the profile
        if ($request->filled('grade_id')) {
            $grade = \App\Models\Grade::find($request->get('grade_id'));
            if ($grade && isset($grade->level_id)) {
                $profile->level_id = $grade->level_id;
                $profile->save();
            }
        }

        // Return updated profile with relationships
        $user->load(['quizMasterProfile.grade', 'quizMasterProfile.level']);
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => $user->quizMasterProfile->load('grade', 'level')->append('subjectModels')->toArray(),
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
            'level_id' => 'nullable|exists:levels,id',
            'subjects' => 'nullable|array',
            'subjects.*' => 'exists:subjects,id',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'bio' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = $user->quizeeProfile;
        
        // Map bio to profile field if provided
        $updateData = $request->only([
            'institution',
            'grade_id',
            'level_id',
            'subjects',
            'first_name',
            'last_name',
        ]);
        
        if ($request->filled('bio')) {
            $updateData['profile'] = $request->get('bio');
        }
        
        $profile->update($updateData);

        // If a grade was provided, also persist the associated level on the profile
        if ($request->filled('grade_id')) {
            $grade = \App\Models\Grade::find($request->get('grade_id'));
            if ($grade && isset($grade->level_id)) {
                $profile->level_id = $grade->level_id;
                $profile->save();
            }
        }

        // Return updated profile with relationships
        $user->load(['quizeeProfile.grade', 'quizeeProfile.level']);
        
        return response()->json([
            'message' => 'Profile updated successfully',
            'profile' => $user->quizeeProfile->load('grade', 'level')->toArray(),
        ]);
    }
}