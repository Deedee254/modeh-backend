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

    public function test_authenticated_user_can_upload_allowed_file()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);

        Storage::disk('public')->assertExists($response->json('path'));
    }

    public function test_upload_rejects_dangerous_file_types()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // Attempting to upload an HTML file should be rejected by mimes validation
        $htmlFile = UploadedFile::fake()->create('malicious.html', 10, 'text/html');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $htmlFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // Attempting to upload a PHP script should be rejected
        $phpFile = UploadedFile::fake()->create('shell.php', 10, 'text/x-php');

        $responsePhp = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $phpFile,
            ]);

        $responsePhp->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_unauthenticated_user_cannot_upload_files()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }
}
