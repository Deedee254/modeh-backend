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
use Faker\Factory as Faker;

class QuizQuestionSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        // 1. Ensure the quiz-master user exists (idempotent)
        $quizMaster = User::firstOrCreate(
            ['email' => 'quiz-master@example.com'],
            [
                'name' => 'quiz-master One',
                'password' => Hash::make('password123'),
                'role' => 'quiz-master'
            ]
        );

        // We'll create up to N quizzes per grade by iterating subjects and topics programmatically
        $grades = Grade::all();
        if ($grades->isEmpty()) {
            // fallback: create a default grade
            $grades = collect([Grade::firstOrCreate(['name' => 'General Knowledge'])]);
        }

        // For each grade, pick up to 2 subjects and for each subject pick up to 2 topics and create a quiz
        foreach ($grades as $grade) {
            $subjects = Subject::where('grade_id', $grade->id)->inRandomOrder()->limit(2)->get();
            if ($subjects->isEmpty()) {
                // create a default subject
                $subjects = collect([Subject::firstOrCreate(['name' => 'General Studies', 'grade_id' => $grade->id], ['created_by' => $quizMaster->id, 'is_approved' => true])]);
            }

            foreach ($subjects as $subject) {
                $topics = Topic::where('subject_id', $subject->id)->inRandomOrder()->limit(2)->get();
                if ($topics->isEmpty()) {
                    $topics = collect([Topic::firstOrCreate(['name' => 'General', 'subject_id' => $subject->id], ['created_by' => $quizMaster->id, 'is_approved' => true])]);
                }

                foreach ($topics as $topic) {
                    // Create or update a deterministic quiz title so seeding is idempotent
                    $title = sprintf('%s — %s Quiz', $subject->name, $topic->name);
                    $quiz = Quiz::updateOrCreate([
                        'title' => $title,
                        'topic_id' => $topic->id,
                    ],[
                        'topic_id' => $topic->id,
                        'user_id' => $quizMaster->id,
                        'grade_id' => $grade->id ?? null,
                        'created_by' => $quizMaster->id,
                        'description' => "Seeded quiz for {$topic->name} ({$subject->name})",
                        'is_approved' => true,
                        'difficulty' => rand(1,4),
                    ]);

                    // Create a variety of realistic questions per quiz (cover all allowed types)
                    $questionTemplates = [];

                    // 1) Simple MCQ
                    $questionTemplates[] = [
                        'type' => 'mcq',
                        'body' => "Which of the following is the capital city of Kenya?",
                        'options' => ['Lagos', 'Nairobi', 'Kigali', 'Accra'],
                        'answers' => [1],
                        'explanation' => 'Nairobi is the capital and largest city of Kenya.',
                        'difficulty' => 2,
                    ];

                    // 2) Multi-select
                    $questionTemplates[] = [
                        'type' => 'multi',
                        'body' => 'Select all prime numbers from the list below.',
                        'options' => ['4', '7', '11', '15'],
                        'answers' => [1,2],
                        'explanation' => '7 and 11 are primes; 4 and 15 are composite.',
                        'difficulty' => 3,
                    ];

                    // 3) Fill in the blank
                    $questionTemplates[] = [
                        'type' => 'fill_blank',
                        'body' => 'The process by which plants make food using sunlight is called _______.',
                        'options' => null,
                        'answers' => ['photosynthesis'],
                        'explanation' => 'Photosynthesis uses light energy to convert CO2 and water into glucose.',
                        'difficulty' => 2,
                    ];

                    // 4) Short answer (text)
                    $questionTemplates[] = [
                        'type' => 'short',
                        'body' => 'Name the chemical element with atomic number 1.',
                        'options' => null,
                        'answers' => ['hydrogen'],
                        'explanation' => 'Hydrogen has atomic number 1.',
                        'difficulty' => 2,
                    ];

                    // 5) Numeric question
                    $questionTemplates[] = [
                        'type' => 'numeric',
                        'body' => 'Calculate the value of 12 * 7.',
                        'options' => null,
                        'answers' => [84],
                        'explanation' => '12 times 7 equals 84.',
                        'difficulty' => 1,
                    ];

                    // 6) Math (more involved) - ensure a real maths question
                    $questionTemplates[] = [
                        'type' => 'numeric',
                        'body' => 'If f(x) = 2x + 3, what is f(5)?',
                        'options' => null,
                        'answers' => [13],
                        'explanation' => 'f(5) = 2*5 + 3 = 13.',
                        'difficulty' => 2,
                    ];

                    // 7) Image MCQ (placeholder image)
                    $questionTemplates[] = [
                        'type' => 'image_mcq',
                        'body' => 'Look at the diagram and choose the correct label for the angle marked θ.',
                        'media_path' => '/seed/images/triangle_theta.jpg',
                        'media_type' => 'image',
                        'media_metadata' => ['caption' => 'Triangle with angle θ shown'],
                        'options' => ['45°', '60°', '90°', '30°'],
                        'answers' => [1],
                        'explanation' => 'From the diagram the marked angle is 60°.',
                        'difficulty' => 3,
                    ];

                    // 8) Video MCQ (youtube placeholder)
                    $questionTemplates[] = [
                        'type' => 'video_mcq',
                        'body' => 'Watch the clip and identify the primary skill demonstrated by the athlete.',
                        'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'options' => ['Dribbling', 'Shooting', 'Passing', 'Tackling'],
                        'answers' => [2],
                        'explanation' => 'The clip focuses on precision passing.',
                        'difficulty' => 2,
                    ];

                    // 9) Audio MCQ (placeholder)
                    $questionTemplates[] = [
                        'type' => 'audio_mcq',
                        'body' => 'Listen to the audio clip and identify the instrument.',
                        'media_path' => '/seed/audio/violin_sample.mp3',
                        'media_type' => 'audio',
                        'options' => ['Guitar', 'Violin', 'Piano', 'Flute'],
                        'answers' => [1],
                        'explanation' => 'The timbre and bowing indicate a violin.',
                        'difficulty' => 2,
                    ];

                    // 10) Image-based MCQ with multiple correct answers (multi-image)
                    $questionTemplates[] = [
                        'type' => 'multi',
                        'body' => 'Which of the following shapes are quadrilaterals?',
                        'options' => ['Triangle', 'Square', 'Rectangle', 'Circle'],
                        'answers' => [1,2],
                        'explanation' => 'Square and Rectangle have four sides.',
                        'difficulty' => 1,
                    ];

                    // Now create/update these templates for the quiz
                    foreach ($questionTemplates as $idx => $qtpl) {
                        // Build the attributes that align with the Question model
                        $uniqueBody = $qtpl['body'] . " (seed {$quiz->id}-{$idx})";

                        $attributes = [
                            'quiz_id' => $quiz->id,
                            'body' => $uniqueBody,
                        ];

                        // infer subject/topic/grade from the quiz itself when possible
                        $quiz->loadMissing('topic.subject.grade');
                        $inferredSubjectId = $quiz->topic->subject_id ?? $subject->id ?? null;
                        $inferredTopicId = $quiz->topic_id ?? $topic->id ?? null;
                        $inferredGradeId = $quiz->topic->grade_id ?? $grade->id ?? null;

                        $values = [
                            'type' => $qtpl['type'],
                            'options' => $qtpl['options'] ?? null,
                            'answers' => $qtpl['answers'] ?? null,
                            'media_path' => $qtpl['media_path'] ?? null,
                            'media_type' => isset($qtpl['media_type']) ? $qtpl['media_type'] : (isset($qtpl['media_path']) ? 'image' : null),
                            'youtube_url' => $qtpl['youtube_url'] ?? null,
                            'media_metadata' => $qtpl['media_metadata'] ?? null,
                            'explanation' => $qtpl['explanation'] ?? null,
                            // hint and solution_steps omitted per schema
                            'difficulty' => $qtpl['difficulty'] ?? 3,
                            'created_by' => $quizMaster->id,
                            'is_approved' => true,
                            'is_banked' => true,
                            'subject_id' => $inferredSubjectId,
                            'topic_id' => $inferredTopicId,
                            'grade_id' => $inferredGradeId,
                            'body' => $uniqueBody,
                        ];

                        Question::updateOrCreate($attributes, $values);
                    }
                }
            }
        }
    }
}
