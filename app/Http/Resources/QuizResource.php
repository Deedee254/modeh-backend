<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * QuizResource
 *
 * Transforms a Quiz model into a JSON response format suitable for API consumption.
 * Includes complete quiz metadata, settings, relationships, and user-specific data like likes and attempts.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property bool $is_approved
 * @property bool $is_draft
 * @property int|null $attempts_count
 */
class QuizResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'youtube_url' => $this->youtube_url,
            'cover_image' => $this->cover_image,
            'is_paid' => $this->is_paid,
            'one_off_price' => $this->one_off_price,
            'timer_seconds' => $this->timer_seconds,
            'per_question_seconds' => $this->per_question_seconds,
            'use_per_question_timer' => $this->use_per_question_timer,
            'attempts_allowed' => $this->attempts_allowed,
            'shuffle_questions' => $this->shuffle_questions,
            'shuffle_answers' => $this->shuffle_answers,
            'visibility' => $this->visibility,
            'scheduled_at' => $this->scheduled_at,
            'difficulty' => $this->difficulty,
            'is_approved' => $this->is_approved,
            'is_draft' => $this->is_draft,
            'likes_count' => $this->likes_count ?? 0,
            'liked' => $this->when(auth('sanctum')->check(), function() {
                // If we've already eager-loaded this with withExists('likes as liked') or similar,
                // it might be available as an attribute.
                if (isset($this->liked)) {
                    return (bool) $this->liked;
                }
                return $this->likes()->where('user_id', auth('sanctum')->id())->exists();
            }, false),
            'questions_count' => $this->questions_count,
            'attempts_count' => $this->attempts_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relations
            'topic_id' => $this->topic_id,
            'topic_name' => $this->topic?->name,
            'topic_slug' => $this->topic?->slug,

            'subject_id' => $this->subject_id,
            'subject_name' => $this->subject?->name ?? $this->topic?->subject?->name,
            'subject_slug' => $this->subject?->slug ?? $this->topic?->subject?->slug,

            'grade_id' => $this->grade_id,
            'grade_name' => $this->grade?->name,
            'grade_slug' => $this->grade?->slug,

            'level_id' => $this->level_id,
            'level_name' => $this->level ? (($this->level->name === 'Tertiary') ? ($this->level->course_name ?? $this->level->name) : $this->level->name) : null,
            'level_slug' => $this->level?->slug,

            // User attempt data
            'last_attempt' => $this->whenLoaded('userLastAttempt'),
        ];
    }
}
