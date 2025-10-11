<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\quizee;
use Illuminate\Support\Facades\Hash;

class quizeeSeeder extends Seeder
{
    public function run()
    {
        $user = User::updateOrCreate([
            'email' => 'quizee@example.com',
        ],[
            'name' => 'quizee One',
            'password' => Hash::make('password123'),
            'role' => 'quizee',
        ]);

        quizee::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'profile' => 'Seeded quizee',
        ]);
    }
}
