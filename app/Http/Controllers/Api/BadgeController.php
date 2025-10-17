<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BadgeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Badge::query();

            try {
                // Filter by badge type if specified
                if ($request->has('for')) {
                    $requestedType = $request->get('for');
                    // Map frontend type names to database criteria_type names
                    $typeMap = [
                        'daily_challenge' => 'daily_completion',
                        'quiz' => 'quiz_score',
                        'battle' => 'battle_wins',
                        'tournament' => 'tournament_wins'
                    ];
                    $criteriaType = $typeMap[$requestedType] ?? $requestedType;
                    $query->where('criteria_type', $criteriaType)
                          ->where('is_active', true);
                }

                // Return only necessary fields
                $badges = $query->select([
                    'id',
                    'name',
                    'slug',
                    'description',
                    'icon',
                    'criteria_type',
                    'points_reward'
                ])->get();

                \Log::info('Badges query result', ['count' => $badges->count(), 'badges' => $badges->toArray()]);
            } catch (\Exception $e) {
                \Log::error('Error in badge query: ' . $e->getMessage());
                throw $e;
            }

            return response()->json([
                'data' => $badges,
                'meta' => [
                    'total' => $badges->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching badges: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching badges',
                'data' => [],
                'meta' => ['total' => 0]
            ], 500);
        }
    }
}