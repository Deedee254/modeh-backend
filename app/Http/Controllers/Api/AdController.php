<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Quiz;
use App\Services\AdMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdController extends Controller
{
    protected AdMatchingService $matchingService;

    public function __construct(AdMatchingService $matchingService)
    {
        $this->matchingService = $matchingService;
    }

    /**
     * Get an eligible ad for a quiz or general placement.
     */
    public function getEligible(Request $request): JsonResponse
    {
        $quiz = null;
        if ($request->filled('quiz_id')) {
            $quiz = Quiz::find($request->input('quiz_id'));
        } elseif ($request->filled('quiz_slug')) {
            $quiz = Quiz::where('slug', $request->input('quiz_slug'))->first();
        }

        $ad = $this->matchingService->findEligibleAd($quiz);

        return response()->json([
            'ok' => true,
            'ad' => $this->matchingService->formatAdPayload($ad),
        ]);
    }

    /**
     * Record an ad impression.
     */
    public function recordImpression(Ad $ad): JsonResponse
    {
        $ad->recordImpression();

        return response()->json([
            'ok' => true,
            'impressions_count' => $ad->impressions_count,
        ]);
    }

    /**
     * Record an ad click.
     */
    public function recordClick(Ad $ad): JsonResponse
    {
        $ad->recordClick();

        return response()->json([
            'ok' => true,
            'clicks_count' => $ad->clicks_count,
        ]);
    }
}
