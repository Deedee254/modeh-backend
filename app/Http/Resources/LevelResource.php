<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LevelResource
 *
 * Transforms a Level model into a JSON response format suitable for API consumption.
 * Includes level metadata and optionally nested grades.
 * Handles special Tertiary level display naming using course_name attribute.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $order
 * @property string|null $description
 * @property string|null $course_name
 */
class LevelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $name = $this->name;
        $lowerName = strtolower($name);
        if (str_contains($lowerName, 'tertiary') || str_contains($lowerName, 'higher education') || str_contains($lowerName, 'university')) {
             $name = $this->course_name ?? $name;
        }

        return [
            'id' => $this->id,
            'name' => $name,
            'slug' => $this->slug,
            'order' => $this->order,
            'description' => $this->description,
            'quizzes_count' => $this->getQuizzesCount(),
            'grades' => GradeResource::collection($this->whenLoaded('grades')),
        ];
    }

    /**
     * Get the total count of quizzes across all grades in this level.
     * Calculates from nested grades if loaded, otherwise returns 0.
     *
     * @return int
     */
    protected function getQuizzesCount(): int
    {
        if (isset($this->quizzes_count)) {
            return (int) $this->quizzes_count;
        }

        // If grades are loaded, calculate from them
        if ($this->relationLoaded('grades')) {
            return (int) $this->grades->reduce(function ($carry, $grade) {
                $gradeCount = $grade->quizzes_count ?? 0;
                if ($grade->relationLoaded('subjects')) {
                    $gradeCount = $grade->subjects->reduce(function ($subjectCarry, $subject) {
                        return $subjectCarry + ($subject->quizzes_count ?? 0);
                    }, 0);
                }
                return $carry + $gradeCount;
            }, 0);
        }

        return 0;
    }
}
