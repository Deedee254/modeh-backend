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
            SubjectSeeder::class,
            TopicSeeder::class,
            QuizeeSeeder::class,
            QuizMasterSeeder::class,
            AdminSeeder::class,
            QuizQuestionSeeder::class,
            \Database\Seeders\PaymentSettingsSeeder::class,
            \Database\Seeders\PackageSeeder::class,
            \Database\Seeders\AssignSubscriptionsSeeder::class,
            // Sponsors and Testimonials
            \Database\Seeders\SponsorSeeder::class,
            \Database\Seeders\TestimonialSeeder::class,
        ]);
    }
}
