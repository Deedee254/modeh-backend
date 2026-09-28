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

            // Priority 1: Specific Quiz matching (exact quiz targeting)
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

            // Priority 2: Taxonomy matching (topic, subject, grade, level)
            // An ad must satisfy ALL taxonomy dimensions it defines targets for.
            $taxonomyQuery = Ad::active()
                ->where('target_type', 'taxonomy')
                ->whereHas('targets')
                // 1. Topic criterion: if ad targets any topics, quiz->topic_id must match one of them
                ->where(function ($q) use ($quiz) {
                    $q->whereDoesntHave('targets', function ($sub) {
                        $sub->where('target_type', 'topic');
                    });
                    if ($quiz->topic_id) {
                        $q->orWhereHas('targets', function ($sub) use ($quiz) {
                            $sub->where('target_type', 'topic')->where('target_id', $quiz->topic_id);
                        });
                    }
                })
                // 2. Subject criterion: if ad targets any subjects, quiz->subject_id must match one of them
                ->where(function ($q) use ($quiz) {
                    $q->whereDoesntHave('targets', function ($sub) {
                        $sub->where('target_type', 'subject');
                    });
                    if ($quiz->subject_id) {
                        $q->orWhereHas('targets', function ($sub) use ($quiz) {
                            $sub->where('target_type', 'subject')->where('target_id', $quiz->subject_id);
                        });
                    }
                })
                // 3. Grade criterion: if ad targets any grades, quiz->grade_id must match one of them
                ->where(function ($q) use ($quiz) {
                    $q->whereDoesntHave('targets', function ($sub) {
                        $sub->where('target_type', 'grade');
                    });
                    if ($quiz->grade_id) {
                        $q->orWhereHas('targets', function ($sub) use ($quiz) {
                            $sub->where('target_type', 'grade')->where('target_id', $quiz->grade_id);
                        });
                    }
                })
                // 4. Level criterion: if ad targets any levels, quiz->level_id must match one of them
                ->where(function ($q) use ($quiz) {
                    $q->whereDoesntHave('targets', function ($sub) {
                        $sub->where('target_type', 'level');
                    });
                    if ($quiz->level_id) {
                        $q->orWhereHas('targets', function ($sub) use ($quiz) {
                            $sub->where('target_type', 'level')->where('target_id', $quiz->level_id);
                        });
                    }
                });

            // Specificity ordering:
            // 1. Ads explicitly targeting topic (most granular) come first.
            // 2. Ads explicitly targeting subject come next.
            // 3. Ads with more total target constraints (composite match) take precedence.
            // 4. Fewest impressions for fair delivery pacing.
            $taxonomyAd = $taxonomyQuery
                ->withCount([
                    'targets as topic_targets_count' => function ($q) {
                        $q->where('target_type', 'topic');
                    },
                    'targets as subject_targets_count' => function ($q) {
                        $q->where('target_type', 'subject');
                    },
                    'targets as total_targets_count'
                ])
                ->orderByDesc('topic_targets_count')
                ->orderByDesc('subject_targets_count')
                ->orderByDesc('total_targets_count')
                ->orderBy('impressions_count', 'asc')
                ->first();

            if ($taxonomyAd) {
                return $taxonomyAd;
            }

            // Priority 3: Global fallback ad (target_type = 'all')
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
