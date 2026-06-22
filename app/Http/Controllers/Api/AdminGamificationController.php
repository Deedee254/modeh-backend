<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\Badge;
use App\Models\QuizeeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AdminGamificationController extends Controller
{
    private function requireAdmin()
    {
        $user = auth()->user() ?? auth('sanctum')->user();
        if ($user && ($user->is_admin ?? false)) return null;
        try {
            if (Gate::allows('viewFilament')) return null;
        } catch (\Throwable $_) {}
        return response()->json(['ok' => false, 'message' => 'Unauthorized'], 403);
    }

    // ==========================================
    // Quizee Levels
    // ==========================================

    public function getQuizeeLevels()
    {
        if ($resp = $this->requireAdmin()) return $resp;
        $levels = QuizeeLevel::orderBy('order')->get();
        return response()->json(['ok' => true, 'quizee_levels' => $levels]);
    }

    public function storeQuizeeLevel(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'min_points' => 'required|numeric|min:0',
            'max_points' => 'nullable|numeric|min:0',
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'color_scheme' => 'nullable|string',
            'order' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $level = QuizeeLevel::create($validator->validated());
        return response()->json(['ok' => true, 'quizee_level' => $level]);
    }

    public function updateQuizeeLevel(Request $request, $id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $level = QuizeeLevel::find($id);
        if (!$level) return response()->json(['ok' => false, 'message' => 'Level not found'], 404);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'min_points' => 'required|numeric|min:0',
            'max_points' => 'nullable|numeric|min:0',
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'color_scheme' => 'nullable|string',
            'order' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $level->update($validator->validated());
        return response()->json(['ok' => true, 'quizee_level' => $level]);
    }

    public function destroyQuizeeLevel($id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $level = QuizeeLevel::find($id);
        if (!$level) return response()->json(['ok' => false, 'message' => 'Level not found'], 404);

        $level->delete();
        return response()->json(['ok' => true, 'message' => 'Deleted successfully']);
    }

    // ==========================================
    // Badges
    // ==========================================

    public function getBadges()
    {
        if ($resp = $this->requireAdmin()) return $resp;
        $badges = Badge::orderBy('created_at', 'desc')->get();
        return response()->json(['ok' => true, 'badges' => $badges]);
    }

    public function storeBadge(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'type' => 'required|string|in:difficulty,mode,meta',
            'criteria' => 'nullable|string',
            'points_reward' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (!isset($data['points_reward'])) $data['points_reward'] = 0;
        
        $badge = Badge::create($data);
        return response()->json(['ok' => true, 'badge' => $badge]);
    }

    public function updateBadge(Request $request, $id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $badge = Badge::find($id);
        if (!$badge) return response()->json(['ok' => false, 'message' => 'Badge not found'], 404);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'type' => 'required|string|in:difficulty,mode,meta',
            'criteria' => 'nullable|string',
            'points_reward' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (!isset($data['points_reward'])) $data['points_reward'] = 0;

        $badge->update($data);
        return response()->json(['ok' => true, 'badge' => $badge]);
    }

    public function destroyBadge($id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $badge = Badge::find($id);
        if (!$badge) return response()->json(['ok' => false, 'message' => 'Badge not found'], 404);

        $badge->delete();
        return response()->json(['ok' => true, 'message' => 'Deleted successfully']);
    }

    // ==========================================
    // Achievements
    // ==========================================

    public function getAchievements()
    {
        if ($resp = $this->requireAdmin()) return $resp;
        // Include usage count if applicable. But let's just get the list first.
        $achievements = Achievement::withCount('users')->orderBy('points', 'desc')->get();
        
        $stats = [
            'total_achievements' => Achievement::count(),
            'active_achievements' => Achievement::where('is_active', true)->count(),
            'total_awarded' => DB::table('achievement_user')->count() ?? 0,
            'badges_count' => Badge::count(),
            'quizee_levels_count' => QuizeeLevel::count()
        ];
        
        return response()->json(['ok' => true, 'achievements' => $achievements, 'stats' => $stats]);
    }

    public function storeAchievement(Request $request)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:achievements,slug',
            'description' => 'required|string|max:1000',
            'icon' => 'nullable|string|max:255',
            'category' => 'required|string',
            'type' => 'required|string',
            'points' => 'required|numeric|min:0|max:1000',
            'criteria_value' => 'required|numeric',
            'color' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (!isset($data['is_active'])) $data['is_active'] = true;

        $achievement = Achievement::create($data);
        return response()->json(['ok' => true, 'achievement' => $achievement]);
    }

    public function updateAchievement(Request $request, $id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $achievement = Achievement::find($id);
        if (!$achievement) return response()->json(['ok' => false, 'message' => 'Achievement not found'], 404);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:achievements,slug,'.$id,
            'description' => 'required|string|max:1000',
            'icon' => 'nullable|string|max:255',
            'category' => 'required|string',
            'type' => 'required|string',
            'points' => 'required|numeric|min:0|max:1000',
            'criteria_value' => 'required|numeric',
            'color' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (!isset($data['is_active'])) $data['is_active'] = false;
        
        $oldPoints = $achievement->points;
        $achievement->update($data);

        // Standard Filament behavior was to update user points if changed, but only on explicit update. We can skip or add if needed.
        if (isset($data['points']) && $oldPoints != $data['points']) {
            try {
                $users = $achievement->users;
                foreach ($users as $u) {
                    $u->points = $u->achievements()->sum('points');
                    $u->save();
                }
            } catch (\Exception $e) {
                Log::error("Failed to update user points: " . $e->getMessage());
            }
        }

        return response()->json(['ok' => true, 'achievement' => $achievement]);
    }

    public function destroyAchievement($id)
    {
        if ($resp = $this->requireAdmin()) return $resp;

        $achievement = Achievement::find($id);
        if (!$achievement) return response()->json(['ok' => false, 'message' => 'Achievement not found'], 404);

        if ($achievement->users()->count() > 0) {
            return response()->json(['ok' => false, 'message' => 'Cannot delete achievements that have been awarded to users.'], 400);
        }

        $achievement->delete();
        return response()->json(['ok' => true, 'message' => 'Deleted successfully']);
    }
}
