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
            // features can be either simple list for display or a structured object with limits
            'features' => [
                'display' => ['Access to free quizzes', 'Daily practice questions'],
                'limits' => [
                    // small limits as example
                    'quiz_results' => 5,
                    'battle_results' => 2
                ]
            ],
            'is_active' => true,
            'duration_days' => 30,
        ]);

        Package::updateOrCreate(['slug' => 'pro'], [
            'title' => 'Pro',
            'short_description' => 'Unlimited access, premium quizzes and analytics',
            'description' => 'Pro plan unlocks premium content, progress analytics and priority support.',
            'price' => 499.00,
            'currency' => 'KES',
            // structured features with unlimited markers
            'features' => [
                'display' => ['Unlimited quizzes', 'Detailed analytics', 'Priority support'],
                'limits' => [
                    'quiz_results' => null, // null === unlimited
                    'battle_results' => null
                ]
            ],
            'is_active' => true,
            'duration_days' => 30,
        ]);
    }
}
