<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * SubjectResource
 *
 * Transforms a Subject model into a JSON response format suitable for API consumption.
 * Includes subject metadata, grade/level information, and related topics and quiz statistics.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property int $grade_id
 * @property bool $is_approved
 */
class SubjectResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon ? Storage::url($this->icon) : null,
            'image' => $this->getRepresentativeImage(),
            'grade_id' => $this->grade_id,
            'grade_name' => $this->grade?->name,
            'level_id' => $this->grade?->level_id,
            'topics_count' => $this->topics_count ?? $this->topics->count(),
            'quizzes_count' => $this->getQuizzesCount(),
            'is_approved' => $this->is_approved,
            'created_at' => $this->created_at,
            'topics' => TopicResource::collection($this->whenLoaded('topics')),
        ];
    }

    /**
     * Get the representative image URL for the subject.
     *
     * Returns the subject's own image if available, otherwise falls back to
     * the cover image of a representative quiz from related topics.
     *
     * @return string|null
     */
    protected function getRepresentativeImage()
    {
        if ($this->image) {
            return Storage::url($this->image);
        }
        
        // Return first quiz cover image if available (cached or loaded)
        return $this->quizzes_cover_image ?? null;
    }

    /**
     * Get the total count of quizzes in this subject.
     *
     * Attempts to use cached quizzes_count attribute first, then falls back
     * to calculating from related topics if loaded.
     *
     * @return int
     */
    protected function getQuizzesCount()
    {
        if (isset($this->quizzes_count)) {
            return $this->quizzes_count;
        }
        
        return $this->topics->sum('quizzes_count');
    }
}
