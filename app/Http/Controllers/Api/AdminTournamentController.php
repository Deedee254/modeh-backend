<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
            'max_participants' => 'nullable|integer|min:2',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'subject_id' => 'required|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'required|exists:grades,id'
        ]);

        $data['created_by'] = $request->user()->id;
        $data['status'] = 'upcoming';

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
            'max_participants' => 'nullable|integer|min:2',
            'entry_fee' => 'nullable|numeric|min:0',
            'rules' => 'nullable|array',
            'status' => 'sometimes|in:upcoming,active,completed',
            'subject_id' => 'sometimes|exists:subjects,id',
            'topic_id' => 'nullable|exists:topics,id',
            'grade_id' => 'sometimes|exists:grades,id'
        ]);

        $tournament->update($data);

        return response()->json($tournament);
    }

    public function attachQuestions(Request $request, Tournament $tournament)
    {
        $request->validate([
            'questions' => 'required|array',
            'questions.*' => 'exists:questions,id'
        ]);

        $attachData = [];
        foreach ($request->questions as $i => $questionId) {
            $attachData[$questionId] = ['position' => $i];
        }

        $tournament->questions()->sync($attachData);

        return response()->json([
            'message' => 'Questions attached successfully',
            'questions' => $tournament->questions()->get()
        ]);
    }

    public function generateMatches(Request $request, Tournament $tournament) 
    {
        // Only generate if tournament is upcoming
        if ($tournament->status !== 'upcoming') {
            return response()->json(['message' => 'Can only generate matches for upcoming tournaments'], 400);
        }

        // Get participants
        $participants = $tournament->participants()->get();
        $count = $participants->count();

        if ($count < 2) {
            return response()->json(['message' => 'Need at least 2 participants'], 400);
        }

        // Randomize participants
        $participants = $participants->shuffle();

        // Generate round-robin matches where each player plays against every other player
        $matches = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $matches[] = [
                    'tournament_id' => $tournament->id,
                    'round' => 1,
                    'player1_id' => $participants[$i]->id,
                    'player2_id' => $participants[$j]->id,
                    'status' => 'scheduled',
                    'scheduled_at' => $tournament->start_date,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        // Insert all battles
        $tournament->battles()->insert($matches);

        // Activate tournament
        $tournament->status = 'active';
        $tournament->save();

        return response()->json([
            'message' => 'Tournament battles generated successfully',
            'battles' => $tournament->battles()->with(['player1', 'player2'])->get()
        ]);
    }

    public function destroy(Tournament $tournament)
    {
        $tournament->delete();
        return response()->json(['message' => 'Tournament deleted successfully']);
    }
}