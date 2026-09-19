<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_allowed_file_types(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('test.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => 'image',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path', 'file_name', 'size', 'mime_type']);
    }

    public function test_unallowed_file_type_is_rejected_in_default_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // Attempting to upload a dangerous HTML script file
        $file = UploadedFile::fake()->create('malicious.html', 10, 'text/html');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => 'uploads',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_executable_php_file_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('shell.php', 10, 'text/x-php');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
