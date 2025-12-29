<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * TopicResource
 *
 * Transforms a Topic model into a JSON response format suitable for API consumption.
 * Includes topic metadata, associated subject/grade information, and quiz statistics.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image
 * @property int $subject_id
 * @property bool $is_approved
 * @property \Carbon\Carbon $created_at
 */
class TopicResource extends JsonResource
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
            'image' => $this->getRepresentativeImage(),
            'subject_id' => $this->subject_id,
            'subject_name' => $this->subject?->name,
            'grade_id' => $this->subject?->grade_id,
            'grade_name' => $this->subject?->grade?->name,
            'quizzes_count' => $this->quizzes_count ?? 0,
            'is_approved' => $this->is_approved,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Get the representative image URL for the topic.
     *
     * Returns the topic's own image if available, otherwise falls back to
     * the cover image of a representative quiz in the topic.
     *
     * @return string|null
     */
    protected function getRepresentativeImage()
    {
        if ($this->image) {
            return Storage::url($this->image);
        }
        
        return $this->quizzes_cover_image ?? null;
    }
}
