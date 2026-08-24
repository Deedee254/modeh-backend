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

    public function test_unauthenticated_user_cannot_upload_files(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('test.png', 100, 'image/png');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_upload_allowed_file_types(): void
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

    public function test_authenticated_user_cannot_upload_disallowed_file_types(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('shell.php', 100, 'text/x-php');

        $response = $this->postJson('/api/uploads', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
