<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Quiz;
use App\Models\Question;
use Illuminate\Support\Facades\Hash;

class QuizQuestionSeeder extends Seeder
{
    public function run()
    {
        // 1. Ensure the quiz-master user exists
        $quizMaster = User::firstOrCreate(
            ['email' => 'quiz-master@example.com'],
            [
                'name' => 'quiz-master One',
                'password' => Hash::make('password123'),
                'role' => 'quiz-master'
            ]
        );

        // 2. Ensure the necessary Grade, Subject, and Topic exist
        $grade = Grade::firstOrCreate(['name' => 'General Knowledge']);
        $subject = Subject::firstOrCreate(
            ['name' => 'Geography', 'grade_id' => $grade->id],
            ['created_by' => $quizMaster->id, 'is_approved' => true]
        );
        $topic = Topic::firstOrCreate(
            ['name' => 'World Capitals', 'subject_id' => $subject->id],
            ['created_by' => $quizMaster->id, 'is_approved' => true]
        );

        // 3. Create the main Quiz
        $quiz = Quiz::create([
            'topic_id' => $topic->id,
            'user_id' => $quizMaster->id,
            'created_by' => $quizMaster->id,
            'title' => 'A Tour of World Capitals',
            'description' => 'A quiz covering famous capital cities around the globe.',
            'is_approved' => true,
            'difficulty' => 2.5, // Medium difficulty
        ]);

        // 4. Create a variety of realistic questions for the quiz
        $questions = [
            [
                'type' => 'mcq',
                'body' => 'What is the capital of Japan?',
                'options' => ['Beijing', 'Seoul', 'Tokyo', 'Bangkok'],
                'answers' => [2],
                'difficulty' => 2,
            ],
            [
                'type' => 'multi',
                'body' => 'Which of the following are capitals in South America?',
                'options' => ['Buenos Aires', 'Mexico City', 'Lima', 'Lisbon'],
                'answers' => [0, 2],
                'difficulty' => 3,
            ],
            [
                'type' => 'fill_blank',
                'body' => 'The capital of Australia is ______.',
                'options' => null,
                'answers' => ['Canberra'],
                'difficulty' => 4,
            ],
            [
                'type' => 'image_mcq',
                'body' => 'Which city\'s famous opera house is shown in the image?',
                'media_path' => '/seed/images/capitals/sydney.jpg',
                'media_type' => 'image',
                'options' => ['Sydney', 'Copenhagen', 'Oslo'],
                'answers' => [0],
                'difficulty' => 2,
            ],
            [
                'type' => 'video_mcq',
                'body' => 'This video shows a tour of which ancient capital city?',
                'youtube_url' => 'https://www.youtube.com/watch?v=DEoIqJsYRYE', // Placeholder video
                'options' => ['Athens', 'Cairo', 'Rome'],
                'answers' => [2],
                'difficulty' => 3,
            ]
        ];

        foreach ($questions as $questionData) {
            Question::create(array_merge($questionData, [
                'quiz_id' => $quiz->id,
                'created_by' => $quizMaster->id,
                'is_approved' => true,
                'is_banked' => true, // Add to question bank
                'subject_id' => $subject->id,
                'topic_id' => $topic->id,
                'grade_id' => $grade->id,
            ]));
        }
    }
}
