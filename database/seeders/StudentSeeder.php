<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run()
    {
        $user = User::updateOrCreate([
            'email' => 'student@example.com',
        ],[
            'name' => 'Student One',
            'password' => Hash::make('password123'),
            'role' => 'student',
        ]);

        Student::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'profile' => 'Seeded student',
        ]);
    }
}
