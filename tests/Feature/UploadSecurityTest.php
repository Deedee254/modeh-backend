<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_allowed_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => 'document',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path', 'file_name', 'size', 'mime_type']);
    }

    public function test_default_type_blocks_dangerous_file_extensions(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $dangerousFiles = [
            'malicious.php' => 'application/x-php',
            'malicious.html' => 'text/html',
            'malicious.sh' => 'application/x-sh',
            'malicious.exe' => 'application/x-msdownload',
        ];

        foreach ($dangerousFiles as $filename => $mime) {
            $file = UploadedFile::fake()->create($filename, 10, $mime);

            $response = $this->actingAs($user, 'sanctum')
                ->postJson('/api/uploads', [
                    'file' => $file,
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        }
    }

    public function test_unauthenticated_user_cannot_upload_files(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('image.jpg', 50, 'image/jpeg');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }
}
