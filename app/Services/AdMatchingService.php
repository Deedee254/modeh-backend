<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Quiz;
use Illuminate\Support\Facades\Log;

class AdMatchingService
{
    /**
     * Find the best eligible ad for a quiz based on targeting priorities.
     */
    public function findEligibleAd(?Quiz $quiz): ?Ad
    {
        try {
            if (!$quiz) {
                return $this->getGlobalAd();
            }

            // Priority 1: Specific Quiz matching
            $quizAd = Ad::active()
                ->where('target_type', 'quiz')
                ->whereHas('targets', function ($query) use ($quiz) {
                    $query->where('target_type', 'quiz')->where('target_id', $quiz->id);
                })
                ->orderBy('impressions_count', 'asc')
                ->first();

            if ($quizAd) {
                return $quizAd;
            }

            // Priority 2: Topic matching
            if ($quiz->topic_id) {
                $topicAd = Ad::active()
                    ->where('target_type', 'taxonomy')
                    ->whereHas('targets', function ($query) use ($quiz) {
                        $query->where('target_type', 'topic')->where('target_id', $quiz->topic_id);
                    })
                    ->orderBy('impressions_count', 'asc')
                    ->first();

                if ($topicAd) {
                    return $topicAd;
                }
            }

            // Priority 3: Subject matching
            if ($quiz->subject_id) {
                $subjectAd = Ad::active()
                    ->where('target_type', 'taxonomy')
                    ->whereHas('targets', function ($query) use ($quiz) {
                        $query->where('target_type', 'subject')->where('target_id', $quiz->subject_id);
                    })
                    ->orderBy('impressions_count', 'asc')
                    ->first();

                if ($subjectAd) {
                    return $subjectAd;
                }
            }

            // Priority 4: Grade or Level matching
            $gradeOrLevelQuery = Ad::active()
                ->where('target_type', 'taxonomy')
                ->whereHas('targets', function ($query) use ($quiz) {
                    $query->where(function ($sub) use ($quiz) {
                        if ($quiz->grade_id) {
                            $sub->where('target_type', 'grade')->where('target_id', $quiz->grade_id);
                        }
                        if ($quiz->level_id) {
                            $sub->orWhere(function ($levelSub) use ($quiz) {
                                $levelSub->where('target_type', 'level')->where('target_id', $quiz->level_id);
                            });
                        }
                    });
                })
                ->orderBy('impressions_count', 'asc');

            if ($quiz->grade_id || $quiz->level_id) {
                $gradeAd = $gradeOrLevelQuery->first();
                if ($gradeAd) {
                    return $gradeAd;
                }
            }

            // Priority 5: Global fallback ad (target_type = 'all')
            return $this->getGlobalAd();

        } catch (\Throwable $e) {
            Log::error('Error matching eligible ad: ' . $e->getMessage(), [
                'quiz_id' => $quiz?->id,
            ]);
            return null;
        }
    }

    /**
     * Get a global active ad
     */
    public function getGlobalAd(): ?Ad
    {
        return Ad::active()
            ->where('target_type', 'all')
            ->orderBy('impressions_count', 'asc')
            ->first();
    }

    /**
     * Format the ad model for client consumption.
     */
    public function formatAdPayload(?Ad $ad): ?array
    {
        if (!$ad) {
            return null;
        }

        return [
            'id' => $ad->id,
            'title' => $ad->title,
            'description' => $ad->description,
            'media_type' => $ad->media_type,
            'media_url' => $ad->media_url,
            'destination_url' => $ad->destination_url,
            'cta_text' => $ad->cta_text ?: 'Learn More',
            'duration_seconds' => $ad->duration_seconds ?: 10,
            'skip_after_seconds' => $ad->skip_after_seconds,
        ];
    }
}
