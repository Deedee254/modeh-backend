<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatGroupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_fetch_group_messages(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Test Group', 'created_by' => $member->id]);
        $group->members()->attach($member->id);

        Message::create([
            'sender_id' => $member->id,
            'group_id' => $group->id,
            'content' => 'Hello group',
            'type' => 'group',
            'is_read' => false,
        ]);

        Sanctum::actingAs($member);

        $response = $this->getJson("/api/chat/messages?group_id={$group->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'messages')
            ->assertJsonFragment(['content' => 'Hello group']);
    }

    public function test_non_member_cannot_fetch_group_messages(): void
    {
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $group = Group::create(['name' => 'Secret Group', 'created_by' => $member->id]);
        $group->members()->attach($member->id);

        Sanctum::actingAs($nonMember);

        $response = $this->getJson("/api/chat/messages?group_id={$group->id}");

        $response->assertStatus(403);
    }

    public function test_member_can_send_group_message(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Test Group', 'created_by' => $member->id]);
        $group->members()->attach($member->id);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/chat/send', [
            'group_id' => $group->id,
            'content' => 'Authorized group message',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('messages', [
            'group_id' => $group->id,
            'sender_id' => $member->id,
            'content' => 'Authorized group message',
        ]);
    }

    public function test_non_member_cannot_send_group_message(): void
    {
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $group = Group::create(['name' => 'Secret Group', 'created_by' => $member->id]);
        $group->members()->attach($member->id);

        Sanctum::actingAs($nonMember);

        $response = $this->postJson('/api/chat/send', [
            'group_id' => $group->id,
            'content' => 'Unauthorized group message',
        ]);

        $response->assertStatus(403);
    }

    public function test_non_member_cannot_mark_group_read(): void
    {
        $member = User::factory()->create();
        $nonMember = User::factory()->create();
        $group = Group::create(['name' => 'Secret Group', 'created_by' => $member->id]);
        $group->members()->attach($member->id);

        Sanctum::actingAs($nonMember);

        $response = $this->postJson('/api/chat/groups/mark-read', [
            'group_id' => $group->id,
        ]);

        $response->assertStatus(403);
    }
}
