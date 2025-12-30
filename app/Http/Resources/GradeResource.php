<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GradeResource
 *
 * Transforms a Grade model into a JSON response format suitable for API consumption.
 * Includes grade metadata, level information, and statistics about related subjects and quizzes.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $display_name
 * @property string|null $description
 * @property int $level_id
 * @property int|null $subjects_count
 */
class GradeResource extends JsonResource
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
            'display_name' => $this->display_name ?? $this->name,
            'description' => $this->description,
            'level_id' => $this->level_id,
            'level_name' => $this->level ? (($this->level->name === 'Tertiary') ? ($this->level->course_name ?? $this->level->name) : $this->level->name) : null,
            'subjects_count' => $this->subjects_count,
            'quizzes_count' => $this->getQuizzesCount(),
            'subjects' => SubjectResource::collection($this->whenLoaded('subjects')),
        ];
    }

    /**
     * Get the total count of quizzes in this grade.
     *
     * Attempts to use cached quizzes_count attribute first, then falls back
     * to calculating from related subjects and their topics if loaded.
     *
     * @return int
     */
    protected function getQuizzesCount()
    {
        if (isset($this->quizzes_count)) {
            return $this->quizzes_count;
        }

        return $this->subjects->sum(function($subject) {
            return $subject->topics->sum('quizzes_count');
        });
    }
}