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
            'qualifier_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'qualifier_question_count' => 'nullable|integer|min:1|max:100',
            'qualifier_days' => 'nullable|integer|min:0|max:365',
            'battle_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'battle_question_count' => 'nullable|integer|min:1|max:100',
            'qualifier_tie_breaker' => 'nullable|in:duration,score_then_duration',
            'bracket_slots' => 'nullable|integer|in:2,4,8',
            'round_delay_days' => 'nullable|integer|min:0|max:365',
        ]);

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'upcoming';
        $data['qualifier_per_question_seconds'] = $data['qualifier_per_question_seconds'] ?? 30;
        $data['qualifier_question_count'] = $data['qualifier_question_count'] ?? 10;
        $data['battle_per_question_seconds'] = $data['battle_per_question_seconds'] ?? 30;
        $data['battle_question_count'] = $data['battle_question_count'] ?? 10;
        $data['qualifier_tie_breaker'] = $data['qualifier_tie_breaker'] ?? 'score_then_duration';
        $data['bracket_slots'] = $data['bracket_slots'] ?? 8;
        $data['qualifier_days'] = array_key_exists('qualifier_days', $data) ? $data['qualifier_days'] : null;
        $data['round_delay_days'] = array_key_exists('round_delay_days', $data) ? $data['round_delay_days'] : null;

        if (!isset($data['end_date']) && isset($data['start_date']) && $data['qualifier_days']) {
            try {
                $sd = \Illuminate\Support\Carbon::parse($data['start_date']);
                $data['end_date'] = $sd->copy()->addDays((int) $data['qualifier_days']);
            } catch (\Throwable $_) {
            }
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
            'qualifier_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'qualifier_question_count' => 'nullable|integer|min:1|max:100',
            'qualifier_days' => 'nullable|integer|min:0|max:365',
            'battle_per_question_seconds' => 'nullable|integer|min:5|max:300',
            'battle_question_count' => 'nullable|integer|min:1|max:100',
            'qualifier_tie_breaker' => 'nullable|in:duration,score_then_duration',
            'bracket_slots' => 'nullable|integer|in:2,4,8',
            'round_delay_days' => 'nullable|integer|min:0|max:365',
        ]);

        $tournament->update($data);

        if (!$tournament->end_date && $tournament->start_date && isset($data['qualifier_days']) && $data['qualifier_days']) {
            try {
                $tournament->end_date = \Illuminate\Support\Carbon::parse($tournament->start_date)->addDays((int) $data['qualifier_days']);
                $tournament->save();
            } catch (\Throwable $_) {
            }
        }

        return response()->json($tournament);
    }

    public function attachQuestions(Request $request, Tournament $tournament)
    {
        $request->validate([
            'questions' => 'required|array',
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
