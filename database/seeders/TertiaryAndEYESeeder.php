<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Grade;
use App\Models\Level;

class TertiaryAndEYESeeder extends Seeder
{
    public function run(): void
    {
        // Ensure we have a tertiary level available (lookup by name since slug column was removed)
        $tertiary = Level::where('name', 'Tertiary / Higher Education')->first();
        if (! $tertiary) {
            $tertiary = Level::create([
                'name' => 'Tertiary / Higher Education',
                'order' => 6,
                'description' => 'Tertiary and higher education',
            ]);
        }

        // Map Pre-Primary grades (EYE)
    // find pre-primary level by name or fallback to first level
    $prePrimaryLevel = Level::where('name', 'Pre-Primary')->first() ?: Level::first();

        $prePrimaryGrades = [
            ['name' => 'Pre-Primary 1 (PP1)', 'slug' => 'pp1'],
            ['name' => 'Pre-Primary 2 (PP2)', 'slug' => 'pp2'],
        ];

        foreach ($prePrimaryGrades as $g) {
            $data = [
                'name' => $g['name'],
                'type' => 'grade',
                'display_name' => $g['name'],
                'is_active' => true,
            ];
            if ($prePrimaryLevel) $data['level_id'] = $prePrimaryLevel->id;
            // Create or update grade by name (slug removed)
            Grade::updateOrCreate(['name' => $g['name']], $data);
        }

        // Tertiary courses and their subjects
        $courses = [
            'Education (Early Childhood, Primary, Secondary, Special Needs)' => [
                'Early Childhood Education', 'Primary Education', 'Secondary Education', 'Special Needs Education'
            ],
            'Business & Economics' => ['Accounting', 'Finance', 'Marketing', 'Entrepreneurship', 'Economics'],
            'Computer Science & Information Technology' => ['Software Development', 'Networking', 'Data Science', 'Artificial Intelligence'],
            'Engineering' => ['Civil Engineering', 'Electrical Engineering', 'Mechanical Engineering', 'Mechatronics', 'Software Engineering', 'Biomedical Engineering'],
            'Health Sciences' => ['Medicine', 'Nursing', 'Pharmacy', 'Public Health', 'Laboratory Science'],
            'Law' => ['Legal Practice', 'Human Rights', 'Constitutional Law', 'Corporate Law'],
            'Architecture & Built Environment' => ['Architectural Design', 'Urban Planning', 'Quantity Surveying', 'Interior Design'],
            'Agriculture & Environmental Studies' => ['Crop Science', 'Animal Production', 'Environmental Management', 'Agribusiness'],
            'Social Sciences' => ['Sociology', 'Psychology', 'Political Science', 'International Relations'],
            'Communication & Media Studies' => ['Journalism', 'Public Relations', 'Film Production', 'Digital Media'],
            'Hospitality & Tourism Management' => ['Hotel Management', 'Travel Operations', 'Event Management', 'Culinary Arts'],
            'Fine Arts & Design' => ['Graphic Design', 'Painting', 'Sculpture', 'Fashion Design'],
            'Sports Science & Physical Education' => ['Sports Management', 'Coaching', 'Kinesiology', 'Fitness Training'],
        ];

        foreach ($courses as $courseName => $subjects) {
            // Create or update the course as a grade entry keyed by name
            $grade = Grade::updateOrCreate(
                ['name' => $courseName],
                [
                    'name' => $courseName,
                    'type' => 'course',
                    'display_name' => $courseName,
                    'is_active' => true,
                    'level_id' => $tertiary->id,
                ]
            );

            // Insert subjects for this course (as subjects linked to the course grade)
            foreach ($subjects as $sub) {
                DB::table('subjects')->updateOrInsert([
                    'grade_id' => $grade->id,
                    'name' => $sub,
                ], [
                    'description' => null,
                    'is_approved' => true,
                    'auto_approve' => true,
                    'approval_requested_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }
}
