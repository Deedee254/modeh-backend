<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\QuizMaster;
use App\Models\Subject;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class QuizMasterSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        // Get all subject IDs to assign to quiz-masters
        $subjectIds = Subject::pluck('id')->toArray();
        if (empty($subjectIds)) {
            $this->command->info('No subjects found. Running SubjectSeeder...');
            $this->call(SubjectSeeder::class);
            $subjectIds = Subject::pluck('id')->toArray();
        }

        // Create the main quiz-master for manual testing (idempotent)
        $user = User::updateOrCreate([
            'email' => 'quiz-master@example.com',
        ],[
            'name' => 'quiz-master One',
            'password' => Hash::make('password123'),
            'social_avatar' => 'https://i.pravatar.cc/300?u=quiz-master@example.com',
            'role' => 'quiz-master',
        ]);

        QuizMaster::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'headline' => 'Your friendly neighborhood quiz-master.',
            'bio' => 'I am a passionate educator with over 10 years of experience in helping quizees achieve their academic goals. My focus is on creating a supportive and engaging learning environment.',
            'subjects' => $faker->randomElements($subjectIds, rand(2, 3)),
        ]);

        // Create 5 additional quiz-masters (idempotent)
        // Use deterministic emails so running the seeder multiple times won't create duplicates.
        for ($i = 2; $i <= 6; $i++) {
            $email = "quiz-master-{$i}@example.com";

            $user = User::updateOrCreate([
                'email' => $email,
            ],[
                'name' => $faker->name,
                'password' => Hash::make('password123'),
                'social_avatar' => 'https://i.pravatar.cc/300?u=' . $email,
                'role' => 'quiz-master',
            ]);

            QuizMaster::updateOrCreate([
                'user_id' => $user->id,
            ],[
                'headline' => $faker->sentence(6),
                'bio' => $faker->paragraphs(3, true),
                'subjects' => $faker->randomElements($subjectIds, rand(2, 4)),
            ]);
        }
    }
}
