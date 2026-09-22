<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate([
            'email' => 'advertiser@example.com',
        ], [
            'name' => 'Modeh Advertiser',
            'password' => Hash::make('password123'),
            'role' => 'advertiser',
            'is_profile_completed' => true,
        ]);
    }
}