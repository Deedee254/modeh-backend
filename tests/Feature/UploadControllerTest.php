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

    public function test_authenticated_user_can_upload_image_with_valid_type(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('test.png');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => 'image',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['url', 'path']);

        $path = $response->json('path');
        $this->assertStringStartsWith('image/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_path_traversal_type_falls_back_to_uploads_folder(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => '../../secret',
            ]);

        $response->assertStatus(201);

        $path = $response->json('path');
        $this->assertStringStartsWith('uploads/', $path);
        $this->assertStringNotContainsString('..', $path);
        Storage::disk('public')->assertExists($path);
    }
}
