<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Quizee;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Level;
use Illuminate\Support\Facades\Hash;

class QuizeeSeeder extends Seeder
{
    public function run()
    {
        // Ensure tertiary level exists
        $tertiaryLevel = Level::where('name', 'Tertiary / Higher Education')->first();
        if (!$tertiaryLevel) {
            $this->command->info('Tertiary level not found. Running LevelsTableSeeder...');
            $this->call(LevelsTableSeeder::class);
            $tertiaryLevel = Level::where('name', 'Tertiary / Higher Education')->first();
        }

        // Ensure Law course exists
        $lawCourse = Grade::where('name', 'Law')->where('type', 'course')->first();
        if (!$lawCourse) {
            $this->command->info('Law course not found. Running TertiaryAndEYESeeder...');
            $this->call(TertiaryAndEYESeeder::class);
            $lawCourse = Grade::where('name', 'Law')->where('type', 'course')->first();
        }

        // Get Law course subjects
        $lawSubjectIds = Subject::where('grade_id', $lawCourse->id)->pluck('id')->toArray();
        if (empty($lawSubjectIds)) {
            $this->command->info('No Law subjects found. Running TertiaryAndEYESeeder...');
            $this->call(TertiaryAndEYESeeder::class);
            $lawSubjectIds = Subject::where('grade_id', $lawCourse->id)->pluck('id')->toArray();
        }

        // Fallback to any subjects if Law has none
        if (empty($lawSubjectIds)) {
            $lawSubjectIds = Subject::pluck('id')->limit(4)->toArray();
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

        // Create main test quizee with tertiary (Law) level
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
            'grade_id' => $lawCourse->id, // Use Law course
            'level_id' => $tertiaryLevel->id, // Use Tertiary level
            'subjects' => $lawSubjectIds, // Use Law subjects
        ]);

        $this->command->info('Quizee seeded with tertiary (Law) level');
    }
}
