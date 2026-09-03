<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EchoTestSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_echo_test_status()
    {
        $response = $this->getJson('/api/echo-test/status');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_send_echo_test_message()
    {
        $response = $this->postJson('/api/echo-test/send', [
            'channel' => 'test-channel',
            'event' => 'test-event',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_status_without_leaking_database_config()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/echo-test/status');

        $response->assertStatus(200);
        $response->assertJsonMissingPath('config.database');
    }
}
