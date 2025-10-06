<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;

class PackageSeeder extends Seeder
{
    public function run()
    {
        Package::updateOrCreate(['slug' => 'starter'], [
            'title' => 'Starter',
            'short_description' => 'Basic access to quizzes and practice questions',
            'description' => 'Starter plan gives you access to core quizzes and practice features.',
            'price' => 0,
            'currency' => 'KES',
            'features' => ['Access to free quizzes', 'Daily practice questions'],
            'is_active' => true,
            'duration_days' => 30,
        ]);

        Package::updateOrCreate(['slug' => 'pro'], [
            'title' => 'Pro',
            'short_description' => 'Unlimited access, premium quizzes and analytics',
            'description' => 'Pro plan unlocks premium content, progress analytics and priority support.',
            'price' => 499.00,
            'currency' => 'KES',
            'features' => ['Unlimited quizzes', 'Detailed analytics', 'Priority support'],
            'is_active' => true,
            'duration_days' => 30,
        ]);
    }
}
