<?php

namespace Database\Factories;

use App\Models\DailyChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;

class DailyChallengeFactory extends Factory
{
    protected $model = DailyChallenge::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'date' => now()->toDateString(),
        ];
    }
}
