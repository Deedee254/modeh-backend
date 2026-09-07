<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_dangerous_file_types_in_default_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Attempting to upload a PHP script without specifying type
        $phpFile = UploadedFile::fake()->create('malicious.php', 10, 'application/x-php');

        $response = $this->postJson('/api/uploads', [
            'file' => $phpFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_rejects_html_files_in_default_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Attempting to upload an HTML file to execute script
        $htmlFile = UploadedFile::fake()->create('exploit.html', 10, 'text/html');

        $response = $this->postJson('/api/uploads', [
            'file' => $htmlFile,
            'type' => 'custom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_allows_valid_image_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $imageFile = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->postJson('/api/uploads', [
            'file' => $imageFile,
            'type' => 'image',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);
    }

    public function test_allows_valid_document_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $pdfFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/uploads', [
            'file' => $pdfFile,
            'type' => 'document',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);
    }
}
