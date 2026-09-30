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

    public function test_allows_safe_file_upload()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path', 'file_name']);
    }

    public function test_blocks_dangerous_file_type_upload()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // Attempting to upload a dangerous PHP script file
        $file = UploadedFile::fake()->create('malicious.php', 10, 'text/x-php');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_blocks_dangerous_html_file_upload()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        // Attempting to upload an HTML file (XSS vector)
        $file = UploadedFile::fake()->create('exploit.html', 10, 'text/html');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
