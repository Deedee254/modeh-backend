<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Badge;

class BadgeFactory extends Factory
{
    protected $model = Badge::class;

    public function definition(): array
    {
        $name = fake()->word();
        return [
            'name' => $name,
            'slug' => \Str::slug($name) . '-' . fake()->randomNumber(3),
            'description' => fake()->sentence(),
            'icon' => null,
            'criteria_type' => 'quiz_score',
            'criteria_conditions' => json_encode(['min_score' => 70]),
            'points_reward' => 50,
            'is_active' => true,
        ];
    }
}
