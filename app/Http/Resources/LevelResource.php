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
            'grades' => GradeResource::collection($this->whenLoaded('grades')),
        ];
    }
}
