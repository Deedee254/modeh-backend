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
    public function run(): void
    {
        $quizMaster = $this->ensureQuizMaster();
        $grades = $this->getGrades();

        foreach ($grades as $grade) {
            $subjects = $this->getSubjects($grade, $quizMaster);
            foreach ($subjects as $subject) {
                $topics = $this->getTopics($subject, $quizMaster);
                foreach ($topics as $topic) {
                    $quiz = $this->createQuiz($quizMaster, $subject, $topic, $grade);
                    $this->createQuestions($quiz, $quizMaster, $subject, $topic, $grade);
                }
            }
        }
    }

    private function ensureQuizMaster(): User
    {
        return User::firstOrCreate(
            ['email' => 'quiz-master@example.com'],
            [
                'name' => 'quiz-master One',
                'password' => Hash::make('password123'),
                'role' => 'quiz-master'
            ]
        );
    }

    private function getGrades()
    {
        $grades = Grade::all();
        if ($grades->isEmpty()) {
            $grades = collect([Grade::firstOrCreate(['name' => 'General Knowledge'])]);
        }
        return $grades;
    }

    private function getSubjects(Grade $grade, User $quizMaster)
    {
        $subjects = Subject::where('grade_id', $grade->id)->inRandomOrder()->limit(2)->get();
        if ($subjects->isEmpty()) {
            $subjects = collect([
                Subject::firstOrCreate(
                    ['name' => 'General Studies', 'grade_id' => $grade->id],
                    ['created_by' => $quizMaster->id, 'is_approved' => true]
                )
            ]);
        }
        return $subjects;
    }

    private function getTopics(Subject $subject, User $quizMaster)
    {
        $topics = Topic::where('subject_id', $subject->id)->inRandomOrder()->limit(2)->get();
        if ($topics->isEmpty()) {
            $topics = collect([
                Topic::firstOrCreate(
                    ['name' => 'General', 'subject_id' => $subject->id],
                    ['created_by' => $quizMaster->id, 'is_approved' => true]
                )
            ]);
        }
        return $topics;
    }

    private function createQuiz(User $quizMaster, Subject $subject, Topic $topic, Grade $grade): Quiz
    {
        $title = sprintf('%s — %s Quiz', $subject->name, $topic->name);
        return Quiz::updateOrCreate(
            [
                'title' => $title,
                'topic_id' => $topic->id,
            ],
            [
                'topic_id' => $topic->id,
                'user_id' => $quizMaster->id,
                'grade_id' => $grade->id ?? null,
                'created_by' => $quizMaster->id,
                'description' => "Seeded quiz for {$topic->name} ({$subject->name})",
                'is_approved' => true,
                'difficulty' => rand(1, 4),
            ]
        );
    }

    private function createQuestions(Quiz $quiz, User $quizMaster, Subject $subject, Topic $topic, Grade $grade): void
    {
        $templates = $this->getQuestionTemplates();

        foreach ($templates as $idx => $template) {
            $this->createQuestion($quiz, $quizMaster, $subject, $topic, $grade, $template, $idx);
        }
    }

    private function createQuestion(Quiz $quiz, User $quizMaster, Subject $subject, Topic $topic, Grade $grade, array $template, int $idx): void
    {
        $uniqueBody = $template['body'] . " (seed {$quiz->id}-{$idx})";

        $attributes = [
            'quiz_id' => $quiz->id,
            'body' => $uniqueBody,
        ];

        $quiz->loadMissing('topic.subject.grade');
        $inferredSubjectId = $quiz->topic->subject_id ?? $subject->id ?? null;
        $inferredTopicId = $quiz->topic_id ?? $topic->id ?? null;
        $inferredGradeId = $quiz->topic->grade_id ?? $grade->id ?? null;

        $values = [
            'type' => $template['type'],
            'options' => $template['options'] ?? null,
            'answers' => $template['answers'] ?? null,
            'media_path' => $template['media_path'] ?? null,
            'media_type' => isset($template['media_type']) ? $template['media_type'] : (isset($template['media_path']) ? 'image' : null),
            'youtube_url' => $template['youtube_url'] ?? null,
            'media_metadata' => $template['media_metadata'] ?? null,
            'explanation' => $template['explanation'] ?? null,
            'difficulty' => $template['difficulty'] ?? 3,
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

    private function getQuestionTemplates(): array
    {
        return [
            [
                'type' => 'mcq',
                'body' => "Which of the following is the capital city of Kenya?",
                'options' => ['Lagos', 'Nairobi', 'Kigali', 'Accra'],
                'answers' => [1],
                'explanation' => 'Nairobi is the capital and largest city of Kenya.',
                'difficulty' => 2,
            ],
            [
                'type' => 'multi',
                'body' => 'Select all prime numbers from the list below.',
                'options' => ['4', '7', '11', '15'],
                'answers' => [1, 2],
                'explanation' => '7 and 11 are primes; 4 and 15 are composite.',
                'difficulty' => 3,
            ],
            [
                'type' => 'fill_blank',
                'body' => 'The process by which plants make food using sunlight is called _______.',
                'options' => null,
                'answers' => ['photosynthesis'],
                'explanation' => 'Photosynthesis uses light energy to convert CO2 and water into glucose.',
                'difficulty' => 2,
            ],
            [
                'type' => 'short',
                'body' => 'Name the chemical element with atomic number 1.',
                'options' => null,
                'answers' => ['hydrogen'],
                'explanation' => 'Hydrogen has atomic number 1.',
                'difficulty' => 2,
            ],
            [
                'type' => 'numeric',
                'body' => 'Calculate the value of 12 * 7.',
                'options' => null,
                'answers' => [84],
                'explanation' => '12 times 7 equals 84.',
                'difficulty' => 1,
            ],
            [
                'type' => 'numeric',
                'body' => 'If f(x) = 2x + 3, what is f(5)?',
                'options' => null,
                'answers' => [13],
                'explanation' => 'f(5) = 2*5 + 3 = 13.',
                'difficulty' => 2,
            ],
            [
                'type' => 'image_mcq',
                'body' => 'Look at the diagram and choose the correct label for the angle marked θ.',
                'media_path' => '/seed/images/triangle_theta.jpg',
                'media_type' => 'image',
                'media_metadata' => ['caption' => 'Triangle with angle θ shown'],
                'options' => ['45°', '60°', '90°', '30°'],
                'answers' => [1],
                'explanation' => 'From the diagram the marked angle is 60°.',
                'difficulty' => 3,
            ],
            [
                'type' => 'video_mcq',
                'body' => 'Watch the clip and identify the primary skill demonstrated by the athlete.',
                'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'options' => ['Dribbling', 'Shooting', 'Passing', 'Tackling'],
                'answers' => [2],
                'explanation' => 'The clip focuses on precision passing.',
                'difficulty' => 2,
            ],
            [
                'type' => 'audio_mcq',
                'body' => 'Listen to the audio clip and identify the instrument.',
                'media_path' => '/seed/audio/violin_sample.mp3',
                'media_type' => 'audio',
                'options' => ['Guitar', 'Violin', 'Piano', 'Flute'],
                'answers' => [1],
                'explanation' => 'The timbre and bowing indicate a violin.',
                'difficulty' => 2,
            ],
            [
                'type' => 'multi',
                'body' => 'Which of the following shapes are quadrilaterals?',
                'options' => ['Triangle', 'Square', 'Rectangle', 'Circle'],
                'answers' => [1, 2],
                'explanation' => 'Square and Rectangle have four sides.',
                'difficulty' => 1,
            ],
        ];
    }
}
