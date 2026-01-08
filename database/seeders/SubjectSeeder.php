<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        // Map of grade_id => subjects
        $subjectsByGrade = [
            1 => [
                'Language Activities (English, Kiswahili, Indigenous)',
                'Mathematical Activities',
                'Environmental Activities',
                'Hygiene & Nutrition',
                'Movement & Creative Activities',
            ],
            2 => [
                'English', 'Kiswahili', 'Indigenous Languages',
                'Mathematics',
                'Environmental Activities',
                'Religious Education (CRE/IRE/HRE)',
                'Art & Craft',
                'Music',
                'Movement & Physical Activities',
            ],
            3 => [
                'English', 'Kiswahili', 'Indigenous Languages',
                'Mathematics',
                'Environmental Activities',
                'Religious Education',
                'Art & Craft',
                'Music',
                'Movement & Physical Activities',
                'Pastoral Program of Instruction (PPI)',
            ],
            4 => [
                'English', 'Kiswahili', 'Home Science',
                'Mathematics',
                'Science & Technology',
                'Social Studies',
                'Agriculture',
                'Religious Education',
                'Creative Arts (Art, Craft, Music)',
                'Physical & Health Education',
                'Life Skills',
            ],
            5 => [
                'English', 'Kiswahili', 'Home Science',
                'Mathematics',
                'Science & Technology',
                'Social Studies',
                'Agriculture',
                'Religious Education',
                'Creative Arts',
                'Physical & Health Education',
                'Life Skills',
            ],
            6 => [
                'English', 'Kiswahili', 'Home Science',
                'Mathematics',
                'Science & Technology',
                'Social Studies',
                'Agriculture',
                'Religious Education',
                'Creative Arts',
                'Physical & Health Education',
                'Life Skills',
            ],
            7 => [
                'English',
                'Kiswahili',
                'Mathematics',
                'Integrated Science',
                'Social Studies',
                'Pre-Technical Studies',
                'Business Studies',
                'ICT',
                'Agriculture',
                'Home Science',
                'Creative Arts',
                'Physical Education & Sports',
                'Religious Education',
                'Life Skills',
            ],
            8 => [
                'English',
                'Kiswahili',
                'Mathematics',
                'Integrated Science',
                'Social Studies',
                'Pre-Technical Studies',
                'Business Studies',
                'ICT',
                'Agriculture',
                'Home Science',
                'Creative Arts',
                'Physical Education & Sports',
                'Religious Education',
                'Life Skills',
            ],
            9 => [
                'English',
                'Kiswahili',
                'Mathematics',
                'Integrated Science',
                'Social Studies',
                'Pre-Technical Studies',
                'Business Studies',
                'ICT',
                'Agriculture',
                'Home Science',
                'Creative Arts',
                'Physical Education & Sports',
                'Religious Education',
                'Life Skills',
            ],
            10 => [
                'Visual Arts',
                'Performing Arts',
                'Sports Science',
                'Media Arts',
                'History',
                'Geography',
                'Business Studies',
                'Religious Education',
                'Life Skills Education',
                'Mathematics',
                'Physics',
                'Chemistry',
                'Biology',
                'Computer Science',
            ],
            11 => [
                'Visual Arts',
                'Performing Arts',
                'Sports Science',
                'Media Arts',
                'History',
                'Geography',
                'Business Studies',
                'Religious Education',
                'Life Skills Education',
                'Mathematics',
                'Physics',
                'Chemistry',
                'Biology',
                'Computer Science',
            ],
            12 => [
                'Visual Arts',
                'Performing Arts',
                'Sports Science',
                'Media Arts',
                'History',
                'Geography',
                'Business Studies',
                'Religious Education',
                'Life Skills Education',
                'Mathematics',
                'Physics',
                'Chemistry',
                'Biology',
                'Computer Science',
            ],
        ];

        $rows = [];
        foreach ($subjectsByGrade as $gradeId => $subjects) {
            foreach ($subjects as $index => $name) {
                $rows[] = [
                    'id' => null, // let DB assign; we'll upsert by composite [grade_id, name]
                    'grade_id' => $gradeId,
                    'name' => $name,
                    'description' => null,
                    'is_approved' => true,
                    'auto_approve' => true,
                    'approval_requested_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Upsert by unique combination of grade_id + name
        // Note: if you have a unique index, this will be more efficient. Without it, still works logically.
        foreach ($rows as $row) {
                DB::table('subjects')->updateOrInsert(
                ['grade_id' => $row['grade_id'], 'name' => $row['name']],
                [
                    'description' => $row['description'],
                    'is_approved' => $row['is_approved'],
                    'auto_approve' => $row['auto_approve'],
                    'approval_requested_at' => $row['approval_requested_at'],
                    'updated_at' => $row['updated_at'],
                    'created_at' => $row['created_at'],
                    'icon' => null,
                    'slug' => \Illuminate\Support\Str::slug($row['name']),
                ]
            );
        }
    }
}