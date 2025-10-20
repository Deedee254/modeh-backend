<?php

namespace Database\Seeders;

use App\Models\QuizeeLevel;
use Illuminate\Database\Seeder;

class QuizeeLevelSeeder extends Seeder
{
    public function run()
    {
        $levels = [
            [
                'name' => 'Novice',
                'min_points' => 0,
                'max_points' => 999,
                'icon' => '🌱',
                'description' => 'Beginning your journey of knowledge',
                'color_scheme' => '#4CAF50',
                'order' => 1
            ],
            [
                'name' => 'Explorer',
                'min_points' => 1000,
                'max_points' => 4999,
                'icon' => '🗺️',
                'description' => 'Venturing into new realms of learning',
                'color_scheme' => '#2196F3',
                'order' => 2
            ],
            [
                'name' => 'Scholar',
                'min_points' => 5000,
                'max_points' => 9999,
                'icon' => '📚',
                'description' => 'Mastering diverse subjects',
                'color_scheme' => '#9C27B0',
                'order' => 3
            ],
            [
                'name' => 'Master',
                'min_points' => 10000,
                'max_points' => 24999,
                'icon' => '🎓',
                'description' => 'A true master of knowledge',
                'color_scheme' => '#FF9800',
                'order' => 4
            ],
            [
                'name' => 'Grand Master',
                'min_points' => 25000,
                'max_points' => 49999,
                'icon' => '👑',
                'description' => 'Among the elite few',
                'color_scheme' => '#F44336',
                'order' => 5
            ],
            [
                'name' => 'Sage',
                'min_points' => 50000,
                'max_points' => 99999,
                'icon' => '🔮',
                'description' => 'Wisdom incarnate',
                'color_scheme' => '#673AB7',
                'order' => 6
            ],
            [
                'name' => 'Legend',
                'min_points' => 100000,
                'max_points' => 249999,
                'icon' => '⭐',
                'description' => 'Your name echoes through the halls of knowledge',
                'color_scheme' => '#FFC107',
                'order' => 7
            ],
            [
                'name' => 'Mythic',
                'min_points' => 250000,
                'max_points' => 499999,
                'icon' => '🌟',
                'description' => 'Achieved mythical status',
                'color_scheme' => '#E91E63',
                'order' => 8
            ],
            [
                'name' => 'King/Queen',
                'min_points' => 500000,
                'max_points' => null,
                'icon' => '👑',
                'description' => 'The pinnacle of achievement',
                'color_scheme' => '#FFD700',
                'order' => 9
            ],
        ];

        foreach ($levels as $level) {
            QuizeeLevel::updateOrCreate(
                ['name' => $level['name']],
                $level
            );
        }
    }
}