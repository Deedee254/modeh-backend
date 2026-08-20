<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_disallowed_attachment_file_type_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $file = UploadedFile::fake()->create('malicious.php', 10, 'text/x-php');

        $response = $this->actingAs($user)->postJson('/api/chat/send', [
            'content' => 'Test message',
            'recipient_id' => $recipient->id,
            'attachments' => [$file],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_valid_attachment_file_type_is_accepted_and_filename_sanitized(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 50, 'application/pdf');

        $response = $this->actingAs($user)->postJson('/api/chat/send', [
            'content' => 'Here is my document',
            'recipient_id' => $recipient->id,
            'attachments' => [$file],
        ]);

        $response->assertStatus(201);
        $data = $response->json('message');
        $this->assertNotEmpty($data['attachments']);
        $this->assertEquals('document.pdf', $data['attachments'][0]['name']);
    }

    public function test_send_message_updates_metrics_safely(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/chat/send', [
            'content' => 'Hello there',
            'recipient_id' => $recipient->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('chat_metric_buckets', [
            'metric_key' => 'messages_per_minute',
        ]);
    }
}
