<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Achievement;

class LevelAchievementsSeeder extends Seeder
{
    public function run()
    {
        $achievements = [
            [
                'name' => 'First Quiz in Level',
                'description' => 'Create your first quiz for a particular level',
                'icon' => '🏁',
                'points' => 50,
                // Align type with payload type used by controllers when creating quizzes
                'type' => 'quiz_created',
                'criteria' => json_encode(['action' => 'create_quiz', 'count' => 1]),
                'criteria_value' => 1,
                'slug' => 'first-quiz-in-level',
                'category' => 'level'
            ],
            [
                'name' => 'Level Builder',
                'description' => 'Create 3 quizzes in the same level',
                'icon' => '🧱',
                'points' => 120,
                'type' => 'quiz_created',
                'criteria' => json_encode(['action' => 'create_quiz', 'count' => 3]),
                'criteria_value' => 3,
                'slug' => 'create-3-quizzes-in-level',
                'category' => 'level'
            ],
            [
                'name' => 'Course Creator',
                'description' => 'Create your first quiz for a tertiary course',
                'icon' => '🎓',
                'points' => 80,
                'type' => 'quiz_created',
                'criteria' => json_encode(['action' => 'create_quiz', 'count' => 1, 'require_course' => true]),
                'criteria_value' => 1,
                'slug' => 'first-quiz-in-course',
                'category' => 'course'
            ],
            [
                'name' => 'Course Builder',
                'description' => 'Create 5 quizzes for the same tertiary course',
                'icon' => '🏗️',
                'points' => 200,
                'type' => 'quiz_created',
                'criteria' => json_encode(['action' => 'create_quiz', 'count' => 5, 'require_course' => true, 'per_grade' => true]),
                'criteria_value' => 5,
                'slug' => 'create-5-quizzes-in-course',
                'category' => 'course'
            ],
            [
                'name' => 'Level Authority',
                'description' => 'Create 10 quizzes in the same level',
                'icon' => '👑',
                'points' => 350,
                'type' => 'quiz_created',
                'criteria' => json_encode(['action' => 'create_quiz', 'count' => 10]),
                'criteria_value' => 10,
                'slug' => 'create-10-quizzes-in-level',
                'category' => 'level'
            ],
        ];

        foreach ($achievements as $achievement) {
            // Use updateOrCreate to make seeder idempotent
            Achievement::updateOrCreate([
                'slug' => $achievement['slug']
            ], $achievement);
        }
    }
}
