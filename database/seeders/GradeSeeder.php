<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['id' => 1, 'name' => 'Grade 1', 'description' => null, 'slug' => 'grade-1'],
            ['id' => 2, 'name' => 'Grade 2', 'description' => null, 'slug' => 'grade-2'],
            ['id' => 3, 'name' => 'Grade 3', 'description' => null, 'slug' => 'grade-3'],
            ['id' => 4, 'name' => 'Grade 4', 'description' => null, 'slug' => 'grade-4'],
            ['id' => 5, 'name' => 'Grade 5', 'description' => null, 'slug' => 'grade-5'],
            ['id' => 6, 'name' => 'Grade 6', 'description' => null, 'slug' => 'grade-6'],
            ['id' => 7, 'name' => 'Grade 7', 'description' => null, 'slug' => 'grade-7'],
            ['id' => 8, 'name' => 'Grade 8', 'description' => null, 'slug' => 'grade-8'],
            ['id' => 9, 'name' => 'Grade 9', 'description' => null, 'slug' => 'grade-9'],
            ['id' => 10, 'name' => 'Grade 10', 'description' => null, 'slug' => 'grade-10'],
            ['id' => 11, 'name' => 'Grade 11', 'description' => null, 'slug' => 'grade-11'],
            ['id' => 12, 'name' => 'Grade 12', 'description' => null, 'slug' => 'grade-12'],
        ];

        // Use upsert by id to keep deterministic IDs across re-seeding
        DB::table('grades')->upsert($grades, ['id'], ['name', 'description', 'slug']);
    }
}