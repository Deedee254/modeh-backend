<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Package;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        $title = fake()->words(2, true);
        return [
            'title' => $title,
            'short_description' => fake()->sentence(),
            'slug' => \Str::slug($title) . '-' . fake()->randomNumber(3),
            'price' => 0,
            'currency' => 'NGN',
            'features' => json_encode([]),
            'is_active' => true,
            'duration_days' => 30,
        ];
    }
}
