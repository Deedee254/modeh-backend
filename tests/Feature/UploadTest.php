<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_upload_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_cannot_upload_dangerous_script_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        // Attempt uploading a dangerous PHP script
        $file = UploadedFile::fake()->create('exploit.php', 10, 'text/x-php');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_authenticated_user_can_upload_allowed_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);
    }
}
