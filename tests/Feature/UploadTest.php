<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_disallowed_file_types_for_default_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Attempt uploading a dangerous executable script (.php)
        $file = UploadedFile::fake()->create('exploit.php', 10, 'text/x-php');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_rejects_html_file_for_default_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Attempt uploading an HTML file with script payload
        $file = UploadedFile::fake()->create('xss.html', 10, 'text/html');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_allows_valid_document_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);
    }

    public function test_allows_valid_image_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('avatar.png');

        $response = $this->postJson('/api/uploads', [
            'type' => 'image',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);
    }
}
