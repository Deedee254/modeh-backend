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

    public function test_upload_sanitizes_type_parameter_to_prevent_path_traversal()
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('test.jpg');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/uploads', [
                'file' => $file,
                'type' => '../../malicious_folder',
            ]);

        $response->assertStatus(201);

        $path = $response->json('path');

        // Path should not contain path traversal dots or directory separators from input
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringStartsWith('malicious_folder', $path);
        Storage::disk('public')->assertExists($path);
    }
}
