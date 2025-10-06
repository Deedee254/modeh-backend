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
    }
}
