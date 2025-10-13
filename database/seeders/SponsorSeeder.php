<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Sponsor;

class SponsorSeeder extends Seeder
{
    public function run(): void
    {
        Sponsor::updateOrCreate([
            'name' => 'Nescafe'
        ], [
            'logo_url' => '/images/sponsors/nescafe.png',
            'website_url' => 'https://www.nescafe.com',
            'description' => 'Coffee brand sponsoring trivia tournaments.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Kellogg'
        ], [
            'logo_url' => '/images/sponsors/kellogg.png',
            'website_url' => 'https://www.kelloggs.com',
            'description' => 'Breakfast brand sponsoring educational tournaments.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Coca-Cola'
        ], [
            'logo_url' => '/images/sponsors/coca-cola.png',
            'website_url' => 'https://www.coca-cola.com',
            'description' => 'Beverage brand sponsoring fun quizzes.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Pepsi'
        ], [
            'logo_url' => '/images/sponsors/pepsi.png',
            'website_url' => 'https://www.pepsi.com',
            'description' => 'Beverage brand sponsoring pop culture tournaments.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'McDonald\'s'
        ], [
            'logo_url' => '/images/sponsors/mcdonalds.png',
            'website_url' => 'https://www.mcdonalds.com',
            'description' => 'Fast food chain sponsoring family-friendly quizzes.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Burger King'
        ], [
            'logo_url' => '/images/sponsors/burger-king.png',
            'website_url' => 'https://www.bk.com',
            'description' => 'Fast food chain sponsoring gaming tournaments.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Nike'
        ], [
            'logo_url' => '/images/sponsors/nike.png',
            'website_url' => 'https://www.nike.com',
            'description' => 'Sportswear brand sponsoring sports trivia.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Adidas'
        ], [
            'logo_url' => '/images/sponsors/adidas.png',
            'website_url' => 'https://www.adidas.com',
            'description' => 'Sportswear brand sponsoring sports tournaments.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Apple'
        ], [
            'logo_url' => '/images/sponsors/apple.png',
            'website_url' => 'https://www.apple.com',
            'description' => 'Technology company sponsoring tech quizzes.',
            'is_active' => true,
        ]);

        Sponsor::updateOrCreate([
            'name' => 'Samsung'
        ], [
            'logo_url' => '/images/sponsors/samsung.png',
            'website_url' => 'https://www.samsung.com',
            'description' => 'Technology company sponsoring innovation challenges.',
            'is_active' => true,
        ]);
    }
}
