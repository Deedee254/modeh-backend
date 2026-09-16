<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Level;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizMasterLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiz_master_can_get_leaderboard_sorted()
    {
        $quizMaster = User::factory()->create([
            'role' => 'quiz-master',
        ]);

        $quizee1 = User::factory()->create(['role' => 'quizee', 'name' => 'Alice']);
        $quizee2 = User::factory()->create(['role' => 'quizee', 'name' => 'Bob']);

        $level = Level::create(['name' => 'Primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'level_id' => $level->id]);
        $subject = Subject::create(['name' => 'Math', 'grade_id' => $grade->id]);
        $topic = Topic::create(['name' => 'Addition', 'subject_id' => $subject->id]);

        $quiz = Quiz::create([
            'title' => 'Sample Quiz',
            'slug' => 'sample-quiz',
            'user_id' => $quizMaster->id,
            'created_by' => $quizMaster->id,
            'topic_id' => $topic->id,
            'subject_id' => $subject->id,
            'grade_id' => $grade->id,
            'level_id' => $level->id,
            'visibility' => 'published',
            'is_approved' => true,
        ]);

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $quizee1->id,
            'points_earned' => 10,
            'score' => 80,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $quizee2->id,
            'points_earned' => 50,
            'score' => 95,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($quizMaster)
            ->getJson('/api/quiz-master/leaderboard?sort_by=points&sort_dir=desc');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $quizee2->id)
            ->assertJsonPath('data.1.id', $quizee1->id);

        $responseAsc = $this->actingAs($quizMaster)
            ->getJson('/api/quiz-master/leaderboard?sort_by=points&sort_dir=asc');

        $responseAsc->assertStatus(200)
            ->assertJsonPath('data.0.id', $quizee1->id)
            ->assertJsonPath('data.1.id', $quizee2->id);
    }
}
