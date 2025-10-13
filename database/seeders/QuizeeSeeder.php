<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Quizee;
use Illuminate\Support\Facades\Hash;

class QuizeeSeeder extends Seeder
{
    public function run()
    {
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
        ]);
    }
}
