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
     * Update the quiz master's profile (partial updates only).
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
        
        // Only update fields that were actually provided in the request
        $updateData = [];
        if ($request->has('institution')) {
            $updateData['institution'] = $request->input('institution');
        }
        if ($request->has('grade_id')) {
            $updateData['grade_id'] = $request->input('grade_id');
        }
        if ($request->has('level_id')) {
            $updateData['level_id'] = $request->input('level_id');
        }
        if ($request->has('subjects')) {
            $updateData['subjects'] = $request->input('subjects');
        }
        if ($request->has('headline')) {
            $updateData['headline'] = $request->input('headline');
        }
        if ($request->has('bio')) {
            $updateData['bio'] = $request->input('bio');
        }

        // Only update if there are fields to update
        if (!empty($updateData)) {
            $profile->update($updateData);
        }

        // If a grade was provided, sync the associated level
        if ($request->has('grade_id') && $request->filled('grade_id')) {
            $grade = \App\Models\Grade::find($request->get('grade_id'));
            if ($grade && isset($grade->level_id)) {
                $profile->level_id = $grade->level_id;
                $profile->save();
            }
        }

        // Return updated user with relationships
        $user->load(['quizMasterProfile.grade', 'quizMasterProfile.level']);
        
        return response()->json([
            'message' => 'Profile updated',
            'user' => $user,
        ]);
    }

    /**
     * Update the quizee's profile (partial updates only).
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
        
        // Only update fields that were actually provided in the request
        $updateData = [];
        if ($request->has('institution')) {
            $updateData['institution'] = $request->input('institution');
        }
        if ($request->has('grade_id')) {
            $updateData['grade_id'] = $request->input('grade_id');
        }
        if ($request->has('level_id')) {
            $updateData['level_id'] = $request->input('level_id');
        }
        if ($request->has('subjects')) {
            $updateData['subjects'] = $request->input('subjects');
        }
        if ($request->has('first_name')) {
            $updateData['first_name'] = $request->input('first_name');
        }
        if ($request->has('last_name')) {
            $updateData['last_name'] = $request->input('last_name');
        }
        if ($request->has('bio')) {
            // Store bio in the 'profile' database field
            $updateData['profile'] = $request->input('bio');
        }

        // Only update if there are fields to update
        if (!empty($updateData)) {
            $profile->update($updateData);
        }

        // If a grade was provided, sync the associated level
        if ($request->has('grade_id') && $request->filled('grade_id')) {
            $grade = \App\Models\Grade::find($request->get('grade_id'));
            if ($grade && isset($grade->level_id)) {
                $profile->level_id = $grade->level_id;
                $profile->save();
            }
        }

        // Return updated user with relationships, convert 'profile' to 'bio' in response
        $user->load(['quizeeProfile.grade', 'quizeeProfile.level']);
        $payload = $user->toArray();
        
        // Convert 'profile' field to 'bio' in the response for API consistency
        if (isset($payload['quizee_profile']) && isset($payload['quizee_profile']['profile'])) {
            $payload['quizee_profile']['bio'] = $payload['quizee_profile']['profile'];
            unset($payload['quizee_profile']['profile']);
        }
        
        return response()->json([
            'message' => 'Profile updated',
            'user' => $payload,
        ]);
    }
}