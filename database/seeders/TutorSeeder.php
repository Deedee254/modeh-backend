<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Tutor;
use App\Models\Subject;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

class TutorSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        // Get all subject IDs to assign to tutors
        $subjectIds = Subject::pluck('id')->toArray();
        if (empty($subjectIds)) {
            $this->command->info('No subjects found. Running SubjectSeeder...');
            $this->call(SubjectSeeder::class);
            $subjectIds = Subject::pluck('id')->toArray();
        }

        // Create the main tutor for manual testing (idempotent)
        $user = User::updateOrCreate([
            'email' => 'tutor@example.com',
        ],[
            'name' => 'Tutor One',
            'password' => Hash::make('password123'),
            'social_avatar' => 'https://i.pravatar.cc/300?u=tutor@example.com',
        ]);

        Tutor::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'headline' => 'Your friendly neighborhood tutor.',
            'bio' => 'I am a passionate educator with over 10 years of experience in helping students achieve their academic goals. My focus is on creating a supportive and engaging learning environment.',
            'subjects' => $faker->randomElements($subjectIds, rand(2, 3)),
        ]);

        // Create 5 additional tutors
        for ($i = 0; $i < 5; $i++) {
            // Use unique emails but guard against duplicates if ran multiple times
            $email = $faker->unique()->safeEmail;
            $user = User::create([
                'name' => $faker->name,
                'email' => $email,
                'password' => Hash::make('password123'),
                'social_avatar' => 'https://i.pravatar.cc/300?u=' . $faker->unique()->uuid,
            ]);

            Tutor::create([
                'user_id' => $user->id,
                'headline' => $faker->sentence(6),
                'bio' => $faker->paragraphs(3, true),
                'subjects' => $faker->randomElements($subjectIds, rand(2, 4)),
            ]);
        }
    }
}
