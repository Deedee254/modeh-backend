<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\Http\Request;

class AdminTournamentController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', 'can:viewFilament']);
    }

    public function show(Tournament $tournament)
    {
        $tournament->load(['subject', 'topic', 'grade', 'level', 'questions']);
        
        return response()->json([
            'tournament' => $tournament,
            'questions' => $tournament->questions()->get(),
            'recommendations' => $tournament->getQuestionRecommendations(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'prize_pool' => 'nullable|numeric|min:0',
            'max_participants' => 'nullable|integer|min:2|max:1000',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'subject_id' => 'required|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'required|exists:grades,id',
            'per_question_seconds' => 'nullable|integer|min:5|max:300',
            'question_count' => 'nullable|integer|min:1|max:100',
            'battle_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'battle_question_count' => 'nullable|integer|min:1|max:100',
            'tie_breaker' => 'nullable|in:duration,score_then_duration',
            'bracket_slots' => 'nullable|integer|in:2,4,8',
            'round_delay_days' => 'nullable|integer|min:0|max:365',
            'sponsor_banner' => 'nullable|image|max:2048',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'upcoming';
        $data['per_question_seconds'] = $data['per_question_seconds'] ?? 30;
        $data['question_count'] = $data['question_count'] ?? 10;
        $data['battle_per_question_seconds'] = $data['battle_per_question_seconds'] ?? 30;
        $data['battle_question_count'] = $data['battle_question_count'] ?? 10;
        $data['tie_breaker'] = $data['tie_breaker'] ?? 'score_then_duration';
        $data['bracket_slots'] = $data['bracket_slots'] ?? 8;
        $data['round_delay_days'] = array_key_exists('round_delay_days', $data) ? $data['round_delay_days'] : null;

        if ($request->hasFile('sponsor_banner')) {
            $data['sponsor_banner'] = $request->file('sponsor_banner')->store('sponsor-banners', 'public');
        }

        $tournament = Tournament::create($data);
        return response()->json($tournament);
    }

    public function update(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'prize_pool' => 'nullable|numeric|min:0',
            'max_participants' => 'nullable|integer|min:2|max:1000',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'status' => 'sometimes|in:upcoming,active,completed',
            'subject_id' => 'sometimes|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'sometimes|exists:grades,id',
            'per_question_seconds' => 'nullable|integer|min:5|max:300',
            'question_count' => 'nullable|integer|min:1|max:100',
            'battle_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'battle_question_count' => 'nullable|integer|min:1|max:100',
            'tie_breaker' => 'nullable|in:duration,score_then_duration',
            'bracket_slots' => 'nullable|integer|in:2,4,8',
            'round_delay_days' => 'nullable|integer|min:0|max:365',
            'sponsor_banner' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('sponsor_banner')) {
            $data['sponsor_banner'] = $request->file('sponsor_banner')->store('sponsor-banners', 'public');
        }

        $tournament->update($data);

        return response()->json($tournament);
    }

    public function attachQuestions(Request $request, Tournament $tournament)
    {
        $request->validate([
            'questions' => 'present|array',
            'questions.*' => 'exists:questions,id',
        ]);

        $attachData = [];
        foreach ($request->questions as $i => $questionId) {
            $attachData[$questionId] = ['position' => $i];
        }

        $tournament->questions()->sync($attachData);

        return response()->json([
            'message' => 'Questions attached successfully',
            'questions' => $tournament->questions()->get(),
        ]);
    }

    public function destroy(Tournament $tournament)
    {
        $tournament->delete();
        return response()->json(['message' => 'Tournament deleted successfully']);
    }
}
