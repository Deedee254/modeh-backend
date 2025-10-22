<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Level;
use App\Models\Grade;

class LevelsTableSeeder extends Seeder
{
    public function run()
    {
        $levels = [
            ['name' => 'Pre-Primary', 'slug' => 'pre-primary', 'order' => 1],
            ['name' => 'Lower Primary', 'slug' => 'lower-primary', 'order' => 2],
            ['name' => 'Upper Primary', 'slug' => 'upper-primary', 'order' => 3],
            ['name' => 'Junior Secondary School (JSS)', 'slug' => 'jss', 'order' => 4],
            ['name' => 'Senior Secondary School (SSS)', 'slug' => 'sss', 'order' => 5],
            ['name' => 'Tertiary / Higher Education', 'slug' => 'tertiary', 'order' => 6],
        ];

        foreach ($levels as $lvl) {
            Level::updateOrCreate(['slug' => $lvl['slug']], $lvl);
        }

        // Try to auto-map existing grades based on their name where obvious
        $allGrades = Grade::all();
        foreach ($allGrades as $grade) {
            $lower = strtolower($grade->name);
            $mapped = null;
            // Pre-primary
            if (str_contains($lower, 'pp') || str_contains($lower, 'pre-primary') || preg_match('/^pp\d+/i', $grade->name)) {
                $mapped = Level::where('slug', 'pre-primary')->first();
            }
            // Lower primary Grades 1-3
            if (preg_match('/grade\s*(1|2|3)/i', $grade->name)) {
                $mapped = Level::where('slug', 'lower-primary')->first();
            }
            // Upper primary Grades 4-6
            if (preg_match('/grade\s*(4|5|6)/i', $grade->name)) {
                $mapped = Level::where('slug', 'upper-primary')->first();
            }
            // JSS Grades 7-9
            if (preg_match('/grade\s*(7|8|9)/i', $grade->name)) {
                $mapped = Level::where('slug', 'jss')->first();
            }
            // SSS Grades 10-12
            if (preg_match('/grade\s*(10|11|12)/i', $grade->name)) {
                $mapped = Level::where('slug', 'sss')->first();
            }

            if ($mapped) {
                $grade->level_id = $mapped->id;
                $grade->save();
            }
        }
    }
}
