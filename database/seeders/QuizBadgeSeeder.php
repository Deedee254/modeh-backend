<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class QuizBadgeSeeder extends Seeder
{
    public function run()
    {
        // Streak-Based Badges
        Badge::updateOrCreate(
            ['slug' => 'quiz-master'],
            [
                'name' => 'Quiz Master',
                'description' => 'Complete 10 quizzes with scores above 80%',
                'icon' => '👨‍🎓',
                'criteria_type' => 'streak',
                'criteria_conditions' => json_encode(['count' => 10, 'min_score' => 80]),
                'points_reward' => 200,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'speed-demon'],
            [
                'name' => 'Speed Demon',
                'description' => 'Complete 5 quizzes in under half the allocated time with scores above 90%',
                'icon' => '⚡',
                'criteria_type' => 'streak',
                'criteria_conditions' => json_encode(['count' => 5, 'time_factor' => 0.5, 'min_score' => 90]),
                'points_reward' => 150,
                'is_active' => true,
            ]
        );

        // Score-Based Badges
        Badge::updateOrCreate(
            ['slug' => 'first-victory'],
            [
                'name' => 'First Victory',
                'description' => 'Get your first 100% score on any quiz',
                'icon' => '🏆',
                'criteria_type' => 'score',
                'criteria_conditions' => json_encode(['score' => 100, 'count' => 1]),
                'points_reward' => 50,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'subject-expert'],
            [
                'name' => 'Subject Expert',
                'description' => 'Score above 90% in 5 different quizzes of the same subject',
                'icon' => '📚',
                'criteria_type' => 'score',
                'criteria_conditions' => json_encode(['subject_score' => 90, 'count' => 5]),
                'points_reward' => 175,
                'is_active' => true,
            ]
        );

        // Time-Based Badges
        Badge::updateOrCreate(
            ['slug' => 'quick-thinker'],
            [
                'name' => 'Quick Thinker',
                'description' => 'Complete a quiz in under 5 minutes with 100% score',
                'icon' => '⏱️',
                'criteria_type' => 'time',
                'criteria_conditions' => json_encode(['max_time' => 300, 'score' => 100]),
                'points_reward' => 100,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'marathon-runner'],
            [
                'name' => 'Marathon Runner',
                'description' => 'Complete 3 long quizzes (20+ questions) with scores above 85%',
                'icon' => '🏃',
                'criteria_type' => 'time',
                'criteria_conditions' => json_encode(['min_questions' => 20, 'count' => 3, 'min_score' => 85]),
                'points_reward' => 125,
                'is_active' => true,
            ]
        );

        // Completion-Based Badges
        Badge::updateOrCreate(
            ['slug' => 'category-champion'],
            [
                'name' => 'Category Champion',
                'description' => 'Complete all quizzes in a category with average score above 85%',
                'icon' => '🌟',
                'criteria_type' => 'completion',
                'criteria_conditions' => json_encode(['category_completion' => 100, 'avg_score' => 85]),
                'points_reward' => 200,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'weekend-warrior'],
            [
                'name' => 'Weekend Warrior',
                'description' => 'Complete 5 quizzes during a weekend with average score above 80%',
                'icon' => '🎮',
                'criteria_type' => 'completion',
                'criteria_conditions' => json_encode(['weekend_count' => 5, 'avg_score' => 80]),
                'points_reward' => 150,
                'is_active' => true,
            ]
        );

        // Special Achievement Badges
        Badge::updateOrCreate(
            ['slug' => 'comeback-king'],
            [
                'name' => 'Comeback King',
                'description' => 'Improve your score by 30% or more when retaking a quiz',
                'icon' => '👑',
                'criteria_type' => 'improvement',
                'criteria_conditions' => json_encode(['improvement' => 30]),
                'points_reward' => 100,
                'is_active' => true,
            ]
        );

        Badge::updateOrCreate(
            ['slug' => 'all-rounder'],
            [
                'name' => 'All-Rounder',
                'description' => 'Score above 80% in quizzes from 5 different subjects',
                'icon' => '🌈',
                'criteria_type' => 'diversity',
                'criteria_conditions' => json_encode(['subjects' => 5, 'min_score' => 80]),
                'points_reward' => 175,
                'is_active' => true,
            ]
        );
    }
}