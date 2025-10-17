<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Quizee;
use App\Models\Subject;
use Illuminate\Support\Facades\Hash;

class QuizeeSeeder extends Seeder
{
    public function run()
    {
        // Get all subject IDs to assign to quizees
        $subjectIds = Subject::pluck('id')->toArray();
        if (empty($subjectIds)) {
            $this->command->info('No subjects found. Running SubjectSeeder...');
            $this->call(SubjectSeeder::class);
            $subjectIds = Subject::pluck('id')->toArray();
        }

        // Get all grade IDs
        $gradeIds = \App\Models\Grade::pluck('id')->toArray();
        if (empty($gradeIds)) {
            $this->command->info('No grades found. Running GradeSeeder...');
            $this->call(GradeSeeder::class);
            $gradeIds = \App\Models\Grade::pluck('id')->toArray();
        }

        $faker = \Faker\Factory::create();

        // Example institutions
        $institutions = [
            'Sunshine Academy',
            'Valley High School',
            'Riverside Elementary',
            'Central High',
            'St. Mary\'s School',
            'Heritage Academy',
            'Mountain View High',
        ];

        // Create main test quizee
        $user = User::updateOrCreate([
            'email' => 'quizee@example.com',
        ],[
            'name' => 'Quizee One',
            'password' => Hash::make('password123'),
            'role' => 'quizee',
        ]);

        Quizee::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'profile' => 'Seeded quizee',
            'institution' => $faker->randomElement($institutions),
            'grade_id' => $faker->randomElement($gradeIds),
            'subjects' => $faker->randomElements($subjectIds, rand(2, 4)),
        ]);
    }
}
