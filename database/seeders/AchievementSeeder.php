<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Achievement;

class AchievementSeeder extends Seeder
{
    public function run()
    {
        $achievements = [
            // Streak Achievements
            [
                'name' => 'Streak Starter',
                'description' => 'Answer 3 questions correctly in a row',
                'icon' => '🔥',
                'points' => 50,
                'type' => 'streak',
                'criteria_value' => 3
            ],
            [
                'name' => 'Hot Streak',
                'description' => 'Answer 5 questions correctly in a row',
                'icon' => '🔥',
                'points' => 100,
                'type' => 'streak',
                'criteria_value' => 5
            ],
            [
                'name' => 'Unstoppable',
                'description' => 'Answer 10 questions correctly in a row',
                'icon' => '🌟',
                'points' => 250,
                'type' => 'streak',
                'criteria_value' => 10
            ],

            // Score Achievements
            [
                'name' => 'Perfect Score',
                'description' => 'Score 100% on a quiz',
                'icon' => '🎯',
                'points' => 500,
                'type' => 'score',
                'criteria_value' => 100
            ],
            [
                'name' => 'Excellence',
                'description' => 'Score 90% or higher on a quiz',
                'icon' => '🏅',
                'points' => 200,
                'type' => 'score',
                'criteria_value' => 90
            ],
            [
                'name' => 'Great Work',
                'description' => 'Score 80% or higher on a quiz',
                'icon' => '👏',
                'points' => 100,
                'type' => 'score',
                'criteria_value' => 80
            ],

            // Completion Achievements
            [
                'name' => 'Completionist',
                'description' => 'Complete all questions in a quiz',
                'icon' => '✅',
                'points' => 150,
                'type' => 'completion',
                'criteria_value' => 100
            ]
        ];

        foreach ($achievements as $achievement) {
            Achievement::create($achievement);
        }
    }
}