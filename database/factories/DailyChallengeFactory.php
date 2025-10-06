<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\DailyChallenge;

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
