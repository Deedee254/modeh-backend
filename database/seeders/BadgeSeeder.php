<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run()
    {
        // Create daily challenge badges using updateOrCreate to avoid duplicates
        Badge::updateOrCreate(
            ['slug' => 'early-bird'],
            [
                'name' => 'Early Bird',
                'description' => 'Complete a daily challenge within the first hour of its release',
                'icon' => '🌅',
                'criteria_type' => 'daily_completion',
                'criteria_conditions' => json_encode(['within_hours' => 1]),
                'points_reward' => 50,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'perfect-score'],
            [
                'name' => 'Perfect Score',
                'description' => 'Get a perfect score on a daily challenge',
                'icon' => '🎯',
                'criteria_type' => 'daily_completion',
                'criteria_conditions' => json_encode(['score' => 100]),
                'points_reward' => 100,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'challenge-streak'],
            [
                'name' => 'Challenge Streak',
                'description' => 'Complete 5 daily challenges in a row',
                'icon' => '🔥',
                'criteria_type' => 'daily_completion',
                'criteria_conditions' => json_encode(['streak' => 5]),
                'points_reward' => 150,
                'is_active' => true,
            ]
        );
    }
}