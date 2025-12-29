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
        return [
            'id' => $this->id,
            'name' => ($this->name === 'Tertiary') ? ($this->course_name ?? $this->name) : $this->name,
            'slug' => $this->slug,
            'order' => $this->order,
            'description' => $this->description,
            'grades' => GradeResource::collection($this->whenLoaded('grades')),
        ];
    }
}
