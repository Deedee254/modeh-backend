<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\Level;
use App\Models\Quizee;
use App\Models\User;
use App\Services\DailyChallengeBaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DailyChallengeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_endpoint_does_not_leak_stack_trace_or_error_details_on_failure(): void
    {
        DB::shouldReceive('raw')
            ->andThrow(new \Exception('Internal DB Connection Error: Secret credentials'));

        $response = $this->getJson('/api/daily-challenges/leaderboard');

        $response->assertStatus(500)
            ->assertJson([
                'message' => 'Error fetching leaderboard data',
            ])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('error')
            ->assertJsonMissing(['Secret credentials']);
    }

    public function test_today_endpoint_does_not_leak_exception_details_when_baker_fails(): void
    {
        $user = User::factory()->create();
        $level = Level::create(['name' => 'Primary', 'slug' => 'primary']);
        $grade = Grade::create(['name' => 'Grade 1', 'slug' => 'grade-1', 'level_id' => $level->id]);

        Quizee::create([
            'user_id' => $user->id,
            'grade_id' => $grade->id,
            'level_id' => $level->id,
        ]);

        $mockBaker = Mockery::mock(DailyChallengeBaker::class);
        $mockBaker->shouldReceive('getOrBakeForGrade')
            ->andThrow(new \Exception('Sensitive DB query failed: SELECT * FROM secret_table'));

        $this->app->instance(DailyChallengeBaker::class, $mockBaker);

        $response = $this->actingAs($user)->getJson('/api/daily-challenges/today');

        $response->assertStatus(500)
            ->assertJson([
                'error' => 'An error occurred while fetching the daily challenge.',
            ])
            ->assertJsonMissing(['Sensitive DB query failed']);
    }
}
