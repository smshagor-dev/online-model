<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontendChatTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Bridge', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function headers(): array
    {
        return ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
    }

    private function authenticate(): User
    {
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        $u = $this->user();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_frontend_prepares_context_and_saves_completed_answer_without_backend_inference(): void
    {
        $this->authenticate();
        Http::fake();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $ticket = $r->json('ticket');
        $id = $r->json('message_id');
        $this->assertDatabaseHas('messages', ['id' => $id, 'status' => 'generating']);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $ticket, 'content' => 'NVIDIA answer', 'status' => 'complete'], $this->headers())->assertOk()->assertJsonPath('stored', true);
        $this->assertDatabaseHas('messages', ['id' => $id, 'content' => 'NVIDIA answer', 'status' => 'complete']);
        Http::assertNothingSent();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $ticket, 'content' => 'Duplicate replacement', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertSame('NVIDIA answer', Message::find($id)->content);
    }

    public function test_bridge_key_and_user_ownership_are_required(): void
    {
        $this->authenticate();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'])->assertForbidden();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Secret'], $this->headers())->assertOk();
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Wrong owner', 'status' => 'complete'], $this->headers())->assertStatus(410);
    }

    public function test_temporary_chat_saves_no_messages_and_ignores_private_preferences(): void
    {
        $u = $this->authenticate();
        $u->forceFill(['custom_instructions' => 'PRIVATE RULE'])->save();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Temporary', 'temporary' => true, 'history' => [['role' => 'user', 'content' => 'Old'], ['role' => 'assistant', 'content' => 'Answer']]], $this->headers())->assertOk();
        $this->assertStringNotContainsString('PRIVATE RULE', json_encode($r->json('messages')));
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Temp answer', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_stop_and_failed_partial_response_release_the_user_lock(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Overlap'], $this->headers())->assertStatus(409);
        Cache::put('stop-message:'.$r->json('message_id'), true, 180);
        $this->postJson('/api/v1/chat/frontend/status', ['ticket' => $r->json('ticket')], $this->headers())->assertJsonPath('cancelled', true);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Partial', 'status' => 'cancelled'], $this->headers())->assertOk();
        $this->assertDatabaseHas('messages', ['content' => 'Partial', 'status' => 'cancelled']);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Next'], $this->headers())->assertOk();
    }

    public function test_empty_failed_response_is_finalized_and_releases_lock(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => '', 'status' => 'complete'], $this->headers())->assertStatus(422);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => '', 'status' => 'failed'], $this->headers())->assertOk();
        $this->assertDatabaseHas('messages', ['id' => $r->json('message_id'), 'content' => '', 'status' => 'failed']);
        $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Next'], $this->headers())->assertOk();
    }

    public function test_deleted_conversation_is_not_recreated_by_late_finish(): void
    {
        $this->authenticate();
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Hello'], $this->headers())->assertOk();
        Conversation::find($r->json('conversation_id'))->delete();
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Late answer', 'status' => 'complete'], $this->headers())->assertOk();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('conversations', 0);
    }
}
