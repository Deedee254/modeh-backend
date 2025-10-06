<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Testimonial;

class TestimonialSeeder extends Seeder
{
    public function run(): void
    {
        Testimonial::updateOrCreate([
            'name' => 'Sarah Johnson'
        ], [
            'quote' => "I've created over 50 quizzes and earned more than $2,000 in just three months. The platform makes it incredibly easy to monetize my knowledge!",
            'role' => 'Quiz Creator',
            'rating' => 5,
            'avatar' => '/images/testimonials/sarah.webp',
            'is_active' => true,
        ]);

        Testimonial::updateOrCreate([
            'name' => 'Michael Chen'
        ], [
            'quote' => "Quitting my part-time job was the best decision ever. I now make double creating fun quizzes about topics I'm passionate about.",
            'role' => 'Top Earner',
            'rating' => 5,
            'avatar' => '/images/testimonials/michael.png',
            'is_active' => true,
        ]);

        Testimonial::updateOrCreate([
            'name' => 'David Rodriguez'
        ], [
            'quote' => "The affiliate program is a game-changer. I've been earning passive income by just sharing my link with friends and followers.",
            'role' => 'Affiliate Marketer',
            'rating' => 5,
            'avatar' => '/images/testimonials/david.png',
            'is_active' => true,
        ]);

        Testimonial::updateOrCreate([
            'name' => 'Emily White'
        ], [
            'quote' => "As a student, I love the variety of quizzes available. It's a fun way to learn and challenge myself.",
            'role' => 'Student',
            'rating' => 5,
            'avatar' => '/images/testimonials/emily.png',
            'is_active' => true,
        ]);
    }
}
