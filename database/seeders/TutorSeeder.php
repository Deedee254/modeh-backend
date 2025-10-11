<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\quiz-master;
use App\Models\Subject;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class quiz-masterSeeder extends Seeder
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
        ]);

        quiz-master::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'headline' => 'Your friendly neighborhood quiz-master.',
            'bio' => 'I am a passionate educator with over 10 years of experience in helping quizees achieve their academic goals. My focus is on creating a supportive and engaging learning environment.',
            'subjects' => $faker->randomElements($subjectIds, rand(2, 3)),
        ]);

        // Create 5 additional quiz-masters
        for ($i = 0; $i < 5; $i++) {
            // Use unique emails but guard against duplicates if ran multiple times
            $email = $faker->unique()->safeEmail;
            $user = User::create([
                'name' => $faker->name,
                'email' => $email,
                'password' => Hash::make('password123'),
                'social_avatar' => 'https://i.pravatar.cc/300?u=' . $faker->unique()->uuid,
            ]);

            quiz-master::create([
                'user_id' => $user->id,
                'headline' => $faker->sentence(6),
                'bio' => $faker->paragraphs(3, true),
                'subjects' => $faker->randomElements($subjectIds, rand(2, 4)),
            ]);
        }
    }
}
