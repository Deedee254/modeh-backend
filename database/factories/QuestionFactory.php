<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Question;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'quiz_id' => null,
            'created_by' => null,
            'type' => 'mcq',
            'body' => fake()->sentence(),
            'options' => json_encode([fake()->word(), fake()->word(), fake()->word(), fake()->word()]),
            'answers' => json_encode([0]),
            'media_path' => null,
            'difficulty' => 3,
            'is_quiz-master_marked' => false,
            'is_approved' => true,
            'is_banked' => false,
            'media_type' => null,
        ];
    }
}
