<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdTarget;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAdController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', function ($request, $next) {
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized. Admin access required.'], 403);
            }
            return $next($request);
        }]);
    }

    /**
     * List all ads with metrics & filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ad::with(['user:id,name,email', 'targets'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->input('target_type'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('description', 'like', $search);
            });
        }

        $ads = $query->paginate($request->input('per_page', 20));

        // Aggregate system metrics
        $totalAds = Ad::count();
        $activeAds = Ad::where('is_active', true)->where('status', 'active')->count();
        $totalImpressions = Ad::sum('impressions_count');
        $totalClicks = Ad::sum('clicks_count');
        $avgCtr = $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0;

        return response()->json([
            'ok' => true,
            'ads' => $ads,
            'stats' => [
                'total_ads' => $totalAds,
                'active_ads' => $activeAds,
                'total_impressions' => $totalImpressions,
                'total_clicks' => $totalClicks,
                'avg_ctr' => $avgCtr,
            ],
        ]);
    }

    /**
     * Get taxonomy and quiz data for populating form dropdowns
     */
    public function formData(): JsonResponse
    {
        $subjects = Subject::select('id', 'name')->orderBy('name')->get();
        $topics = Topic::select('id', 'name', 'subject_id')->orderBy('name')->get();
        $grades = Grade::select('id', 'name')->orderBy('name')->get();
        $levels = Level::select('id', 'name')->orderBy('name')->get();
        $quizzes = Quiz::select('id', 'title', 'slug')->orderBy('title')->limit(200)->get();
        $advertisers = User::where('role', 'advertiser')->select('id', 'name', 'email')->get();

        return response()->json([
            'ok' => true,
            'subjects' => $subjects,
            'topics' => $topics,
            'grades' => $grades,
            'levels' => $levels,
            'quizzes' => $quizzes,
            'advertisers' => $advertisers,
        ]);
    }

    /**
     * Create a new Ad
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'media_type' => 'required|in:image,video',
            'media_url' => 'required|string',
            'destination_url' => 'nullable|url',
            'cta_text' => 'nullable|string|max:50',
            'duration_seconds' => 'required|integer|min:3|max:120',
            'skip_after_seconds' => 'nullable|integer|min:0|max:60',
            'target_type' => 'required|in:all,quiz,taxonomy',
            'is_active' => 'boolean',
            'status' => 'nullable|in:draft,pending_approval,active,paused,completed',
            'user_id' => 'nullable|exists:users,id',
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required_with:targets|in:quiz,subject,topic,grade,level',
            'targets.*.target_id' => 'required_with:targets|integer',
        ]);

        DB::beginTransaction();
        try {
            $ad = Ad::create([
                'user_id' => $validated['user_id'] ?? null,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'media_type' => $validated['media_type'],
                'media_url' => $validated['media_url'],
                'destination_url' => $validated['destination_url'] ?? null,
                'cta_text' => $validated['cta_text'] ?: 'Learn More',
                'duration_seconds' => $validated['duration_seconds'],
                'skip_after_seconds' => $validated['skip_after_seconds'] ?? null,
                'target_type' => $validated['target_type'],
                'is_active' => $validated['is_active'] ?? true,
                'status' => $validated['status'] ?? 'active',
            ]);

            if (!empty($validated['targets']) && $validated['target_type'] !== 'all') {
                foreach ($validated['targets'] as $target) {
                    AdTarget::create([
                        'ad_id' => $ad->id,
                        'target_type' => $target['target_type'],
                        'target_id' => $target['target_id'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => 'Ad created successfully',
                'ad' => $ad->load('targets'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Failed to create ad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View single Ad
     */
    public function show(Ad $ad): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'ad' => $ad->load(['user:id,name,email', 'targets']),
        ]);
    }

    /**
     * Update an existing Ad
     */
    public function update(Request $request, Ad $ad): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'media_type' => 'required|in:image,video',
            'media_url' => 'required|string',
            'destination_url' => 'nullable|url',
            'cta_text' => 'nullable|string|max:50',
            'duration_seconds' => 'required|integer|min:3|max:120',
            'skip_after_seconds' => 'nullable|integer|min:0|max:60',
            'target_type' => 'required|in:all,quiz,taxonomy',
            'is_active' => 'boolean',
            'status' => 'nullable|in:draft,pending_approval,active,paused,completed',
            'user_id' => 'nullable|exists:users,id',
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required_with:targets|in:quiz,subject,topic,grade,level',
            'targets.*.target_id' => 'required_with:targets|integer',
        ]);

        DB::beginTransaction();
        try {
            $ad->update([
                'user_id' => $validated['user_id'] ?? $ad->user_id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'media_type' => $validated['media_type'],
                'media_url' => $validated['media_url'],
                'destination_url' => $validated['destination_url'] ?? null,
                'cta_text' => $validated['cta_text'] ?: 'Learn More',
                'duration_seconds' => $validated['duration_seconds'],
                'skip_after_seconds' => $validated['skip_after_seconds'] ?? null,
                'target_type' => $validated['target_type'],
                'is_active' => $validated['is_active'] ?? $ad->is_active,
                'status' => $validated['status'] ?? $ad->status,
            ]);

            // Sync targets
            $ad->targets()->delete();
            if (!empty($validated['targets']) && $validated['target_type'] !== 'all') {
                foreach ($validated['targets'] as $target) {
                    AdTarget::create([
                        'ad_id' => $ad->id,
                        'target_type' => $target['target_type'],
                        'target_id' => $target['target_id'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => 'Ad updated successfully',
                'ad' => $ad->load('targets'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Failed to update ad: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleStatus(Ad $ad): JsonResponse
    {
        $ad->is_active = !$ad->is_active;
        $ad->status = $ad->is_active ? 'active' : 'paused';
        $ad->save();

        return response()->json([
            'ok' => true,
            'is_active' => $ad->is_active,
            'status' => $ad->status,
        ]);
    }

    /**
     * Delete Ad
     */
    public function destroy(Ad $ad): JsonResponse
    {
        $ad->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Ad deleted successfully',
        ]);
    }
}
