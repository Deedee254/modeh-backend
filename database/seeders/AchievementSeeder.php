<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Achievement;

class AchievementSeeder extends Seeder
{
    public function run()
    {
        $achievements = [
            // Time-based Achievements
            [
                'name' => 'Speed Demon',
                'description' => 'Complete a quiz in under 5 minutes with 100% accuracy',
                'icon' => '⚡',
                'points' => 100,
                'type' => 'time',
                'criteria_value' => 300,
                'slug' => 'speed-demon',
                'category' => 'time'
            ],
            [
                'name' => 'Night Owl',
                'description' => 'Complete 5 quizzes between 10 PM and 5 AM',
                'icon' => '🌙',
                'points' => 75,
                'type' => 'time',
                'criteria_value' => 5,
                'slug' => 'night-owl',
                'category' => 'time'
            ],
            
            // Subject-based Achievements
            [
                'name' => 'Math Master',
                'description' => 'Score 90% or higher in 10 Math quizzes',
                'icon' => '📐',
                'points' => 200,
                'type' => 'subject',
                'criteria_value' => 10,
                'slug' => 'math-master',
                'category' => 'subject'
            ],
            [
                'name' => 'Science Whiz',
                'description' => 'Complete 15 Science quizzes with at least 85% accuracy',
                'icon' => '🧬',
                'points' => 200,
                'type' => 'subject',
                'criteria_value' => 15,
                'slug' => 'science-whiz',
                'category' => 'subject'
            ],
            
            // Improvement-based Achievements
            [
                'name' => 'Rising Star',
                'description' => 'Improve your score by 20% on a retaken quiz',
                'icon' => '⭐',
                'points' => 150,
                'type' => 'improvement',
                'criteria_value' => 20,
                'slug' => 'rising-star',
                'category' => 'improvement'
            ],
            [
                'name' => 'Steady Progress',
                'description' => 'Maintain an upward trend in scores for 5 consecutive quizzes',
                'icon' => '📈',
                'points' => 175,
                'type' => 'improvement',
                'criteria_value' => 5,
                'slug' => 'steady-progress',
                'category' => 'improvement'
            ],
            
            // Weekend Warrior Achievements
            [
                'name' => 'Weekend Warrior',
                'description' => 'Complete 10 quizzes during weekends',
                'icon' => '🏰',
                'points' => 125,
                'type' => 'weekend',
                'criteria_value' => 10,
                'slug' => 'weekend-warrior',
                'category' => 'weekend'
            ],
            [
                'name' => 'Saturday Scholar',
                'description' => 'Score 100% on 3 quizzes in a single Saturday',
                'icon' => '📚',
                'points' => 150,
                'type' => 'weekend',
                'criteria_value' => 3,
                'slug' => 'saturday-scholar',
                'category' => 'weekend'
            ],
            
            // Topic-based Achievements
            [
                'name' => 'Topic Explorer',
                'description' => 'Complete quizzes in 5 different topics',
                'icon' => '🗺️',
                'points' => 100,
                'type' => 'topic',
                'criteria_value' => 5,
                'slug' => 'topic-explorer',
                'category' => 'topic'
            ],
            [
                'name' => 'Topic Master',
                'description' => 'Score 90% or higher in all quizzes of a single topic',
                'icon' => '🎯',
                'points' => 250,
                'type' => 'topic',
                'criteria_value' => 1,
                'slug' => 'topic-master',
                'category' => 'topic'
            ],
            
            // Daily Challenge Achievements
            [
                'name' => 'Challenge Champion',
                'description' => 'Complete 7 daily challenges in a row',
                'icon' => '🏆',
                'points' => 300,
                'type' => 'daily_challenge',
                'criteria_value' => 7,
                'slug' => 'challenge-champion',
                'category' => 'daily_challenge'
            ],
            [
                'name' => 'Daily Dedication',
                'description' => 'Complete 30 daily challenges in total',
                'icon' => '📅',
                'points' => 400,
                'type' => 'daily_challenge',
                'criteria_value' => 30,
                'slug' => 'daily-dedication',
                'category' => 'daily_challenge'
            ],
            
            // Streak Achievements
            [
                'name' => 'Streak Starter',
                'description' => 'Answer 3 questions correctly in a row',
                'icon' => '🔥',
                'points' => 50,
                'type' => 'streak',
                'criteria_value' => 3,
                'slug' => 'streak-starter',
                'category' => 'streak'
            ],
            [
                'name' => 'Hot Streak',
                'description' => 'Answer 5 questions correctly in a row',
                'icon' => '🔥',
                'points' => 100,
                'type' => 'streak',
                'criteria_value' => 5,
                'slug' => 'hot-streak'
            ],
            [
                'name' => 'Unstoppable',
                'description' => 'Answer 10 questions correctly in a row',
                'icon' => '🌟',
                'points' => 250,
                'type' => 'streak',
                'criteria_value' => 10,
                'slug' => 'unstoppable'
            ],

            // Score Achievements
            [
                'name' => 'Perfect Score',
                'description' => 'Score 100% on a quiz',
                'icon' => '🎯',
                'points' => 500,
                'type' => 'score',
                'criteria_value' => 100,
                'slug' => 'perfect-score'
            ],
            [
                'name' => 'Excellence',
                'description' => 'Score 90% or higher on a quiz',
                'icon' => '🏅',
                'points' => 200,
                'type' => 'score',
                'criteria_value' => 90,
                'slug' => 'excellence'
            ],
            [
                'name' => 'Great Work',
                'description' => 'Score 80% or higher on a quiz',
                'icon' => '👏',
                'points' => 100,
                'type' => 'score',
                'criteria_value' => 80,
                'slug' => 'great-work'
            ],

            // Completion Achievements
            [
                'name' => 'Completionist',
                'description' => 'Complete all questions in a quiz',
                'icon' => '✅',
                'points' => 150,
                'type' => 'completion',
                'criteria_value' => 100,
                'slug' => 'completionist'
            ]
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(
                ['slug' => $achievement['slug']],
                $achievement
            );
        }
    }
}