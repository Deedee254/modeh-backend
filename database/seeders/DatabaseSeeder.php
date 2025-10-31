<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create one of each role for development/testing
        $this->call([
            GradeSeeder::class,
            LevelsTableSeeder::class,
            TertiaryAndEYESeeder::class,
            SubjectSeeder::class,
            TopicSeeder::class,
            QuizeeSeeder::class,
            QuizMasterSeeder::class,
            AdminSeeder::class,
            BadgeSeeder::class,
            AchievementSeeder::class,
            LevelAchievementsSeeder::class,
            // QuizBadgeSeeder::class,
            // QuizQuestionSeeder::class,
            PaymentSettingsSeeder::class,
            PackageSeeder::class,
            AssignSubscriptionsSeeder::class,
            // Sponsors and Testimonials
            SponsorSeeder::class,
            TestimonialSeeder::class,
            // Chat messages
            ChatMessageSeeder::class,
            // Quizee Levels
            QuizeeLevelSeeder::class,
        ]);
    }
}
