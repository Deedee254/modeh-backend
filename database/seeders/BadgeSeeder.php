<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run()
    {
        // Create daily challenge badges
        Badge::create([
            'name' => 'Early Bird',
            'slug' => 'early-bird',
            'description' => 'Complete a daily challenge within the first hour of its release',
            'icon' => '🌅',
            'criteria_type' => 'daily_completion',
            'criteria_conditions' => json_encode(['within_hours' => 1]),
            'points_reward' => 50,
            'is_active' => true,
        ]);

        Badge::create([
            'name' => 'Perfect Score',
            'slug' => 'perfect-score',
            'description' => 'Get a perfect score on a daily challenge',
            'icon' => '🎯',
            'criteria_type' => 'daily_completion',
            'criteria_conditions' => json_encode(['score' => 100]),
            'points_reward' => 100,
            'is_active' => true,
        ]);

        Badge::create([
            'name' => 'Challenge Streak',
            'slug' => 'challenge-streak',
            'description' => 'Complete 5 daily challenges in a row',
            'icon' => '🔥',
            'criteria_type' => 'daily_completion',
            'criteria_conditions' => json_encode(['streak' => 5]),
            'points_reward' => 150,
            'is_active' => true,
        ]);
    }
}