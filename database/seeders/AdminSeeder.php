<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $user = User::updateOrCreate([
            'email' => 'admin@example.com',
        ],[
            'name' => 'Admin One',
            'password' => Hash::make('adminpass'),
            'role' => 'admin',
        ]);

        Admin::updateOrCreate([
            'user_id' => $user->id,
        ],[
            'name' => 'Seeded Admin',
        ]);
    }
}
