<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GradeSeeder extends Seeder
{
    public function run(): void
    {
        $grades = [
            ['id' => 1, 'name' => 'Grade 1', 'description' => null],
            ['id' => 2, 'name' => 'Grade 2', 'description' => null],
            ['id' => 3, 'name' => 'Grade 3', 'description' => null],
            ['id' => 4, 'name' => 'Grade 4', 'description' => null],
            ['id' => 5, 'name' => 'Grade 5', 'description' => null],
            ['id' => 6, 'name' => 'Grade 6', 'description' => null],
            ['id' => 7, 'name' => 'Grade 7', 'description' => null],
            ['id' => 8, 'name' => 'Grade 8', 'description' => null],
            ['id' => 9, 'name' => 'Grade 9', 'description' => null],
            ['id' => 10, 'name' => 'Grade 10', 'description' => null],
            ['id' => 11, 'name' => 'Grade 11', 'description' => null],
            ['id' => 12, 'name' => 'Grade 12', 'description' => null],
        ];

        // Use upsert by id to keep deterministic IDs across re-seeding
        DB::table('grades')->upsert($grades, ['id'], ['name', 'description']);
    }
}