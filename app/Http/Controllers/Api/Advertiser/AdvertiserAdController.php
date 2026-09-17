<?php

namespace App\Http\Controllers\Api\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdTarget;
use App\Models\Grade;
use App\Models\Level;
use App\Models\Quiz;
use App\Models\Subject;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvertiserAdController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth:sanctum', function ($request, $next) {
            $user = $request->user();
            if (!$user || (!$user->isAdvertiser() && !$user->isAdmin())) {
                return response()->json(['ok' => false, 'message' => 'Unauthorized. Advertiser access required.'], 403);
            }
            return $next($request);
        }]);
    }

    /**
     * List advertiser's ads with metrics
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Ad::where('user_id', $user->id)->with('targets')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $ads = $query->paginate($request->input('per_page', 20));

        // Aggregate metrics for this advertiser
        $totalAds = Ad::where('user_id', $user->id)->count();
        $activeAds = Ad::where('user_id', $user->id)->where('is_active', true)->where('status', 'active')->count();
        $totalImpressions = Ad::where('user_id', $user->id)->sum('impressions_count');
        $totalClicks = Ad::where('user_id', $user->id)->sum('clicks_count');
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
     * Targeting options for advertiser campaign creation
     */
    public function formData(): JsonResponse
    {
        $subjects = Subject::select('id', 'name')->orderBy('name')->get();
        $topics = Topic::select('id', 'name', 'subject_id')->orderBy('name')->get();
        $grades = Grade::select('id', 'name')->orderBy('name')->get();
        $levels = Level::select('id', 'name')->orderBy('name')->get();
        $quizzes = Quiz::where('visibility', 'public')->select('id', 'title', 'slug')->orderBy('title')->limit(200)->get();

        return response()->json([
            'ok' => true,
            'subjects' => $subjects,
            'topics' => $topics,
            'grades' => $grades,
            'levels' => $levels,
            'quizzes' => $quizzes,
        ]);
    }

    /**
     * Create a new Ad campaign
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'media_type' => 'required|in:image,video',
            'media_url' => 'required|string',
            'destination_url' => 'nullable|url',
            'cta_text' => 'nullable|string|max:50',
            'duration_seconds' => 'required|integer|min:3|max:60',
            'skip_after_seconds' => 'nullable|integer|min:0|max:30',
            'target_type' => 'required|in:all,quiz,taxonomy',
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required_with:targets|in:quiz,subject,topic,grade,level',
            'targets.*.target_id' => 'required_with:targets|integer',
        ]);

        DB::beginTransaction();
        try {
            $ad = Ad::create([
                'user_id' => $user->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'media_type' => $validated['media_type'],
                'media_url' => $validated['media_url'],
                'destination_url' => $validated['destination_url'] ?? null,
                'cta_text' => $validated['cta_text'] ?: 'Learn More',
                'duration_seconds' => $validated['duration_seconds'],
                'skip_after_seconds' => $validated['skip_after_seconds'] ?? null,
                'target_type' => $validated['target_type'],
                'is_active' => true,
                'status' => 'active',
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
                'message' => 'Campaign created successfully',
                'ad' => $ad->load('targets'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Failed to create campaign: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an ad campaign owned by advertiser
     */
    public function update(Request $request, Ad $ad): JsonResponse
    {
        $user = $request->user();
        if ($ad->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'media_type' => 'required|in:image,video',
            'media_url' => 'required|string',
            'destination_url' => 'nullable|url',
            'cta_text' => 'nullable|string|max:50',
            'duration_seconds' => 'required|integer|min:3|max:60',
            'skip_after_seconds' => 'nullable|integer|min:0|max:30',
            'target_type' => 'required|in:all,quiz,taxonomy',
            'is_active' => 'boolean',
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required_with:targets|in:quiz,subject,topic,grade,level',
            'targets.*.target_id' => 'required_with:targets|integer',
        ]);

        DB::beginTransaction();
        try {
            $ad->update([
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
                'status' => ($validated['is_active'] ?? $ad->is_active) ? 'active' : 'paused',
            ]);

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
                'message' => 'Campaign updated successfully',
                'ad' => $ad->load('targets'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => 'Failed to update campaign: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle pause / active
     */
    public function toggleStatus(Request $request, Ad $ad): JsonResponse
    {
        $user = $request->user();
        if ($ad->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

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
     * Delete campaign
     */
    public function destroy(Request $request, Ad $ad): JsonResponse
    {
        $user = $request->user();
        if ($ad->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['ok' => false, 'message' => 'Forbidden'], 403);
        }

        $ad->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }
}
