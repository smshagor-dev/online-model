<?php

namespace Tests\Feature;

use App\Jobs\RememberConversation;
use App\Models\AiModel;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AutomaticMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomaticMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Memory', 'email' => uniqid().'@example.org', 'password' => 'StrongPassword123']);
    }

    public function test_automatic_memory_requires_consent_and_deduplicates_candidates(): void
    {
        $user = $this->user();
        $service = app(AutomaticMemory::class);
        $this->assertSame(0, $service->remember($user, ['Prefers Bangla answers']));
        $user->forceFill(['automatic_memory' => true, 'memory_enabled' => true])->save();
        $this->assertSame(1, $service->remember($user, ['Prefers Bangla answers', 'Prefers Bangla answers', 'API key is secret']));
        $this->assertDatabaseCount('memories', 1);
        $user->forceFill(['automatic_memory' => false])->save();
        $this->assertSame(0, $service->remember($user, ['Another preference']));
        $user->forceFill(['is_active' => false, 'automatic_memory' => true])->save();
        $this->assertSame(0, $service->remember($user, ['Inactive account preference']));
    }

    public function test_hosted_memory_is_saved_only_after_completed_owned_non_temporary_turn(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $user->forceFill(['automatic_memory' => true, 'memory_enabled' => true])->save();
        config(['models.frontend_chat_key' => str_repeat('b', 32)]);
        $headers = ['X-Chat-Bridge-Key' => str_repeat('b', 32)];
        $r = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'I prefer Bangla'], $headers)->assertOk()->assertJsonPath('automatic_memory', true);
        $payload = ['ticket' => $r->json('ticket'), 'memories' => ['Prefers Bangla answers']];
        $this->postJson('/api/v1/chat/frontend/memory', $payload, $headers)->assertStatus(409);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $r->json('ticket'), 'content' => 'Understood', 'status' => 'complete'], $headers)->assertOk();
        $this->postJson('/api/v1/chat/frontend/memory', $payload, $headers)->assertOk()->assertJsonPath('saved', 1);
        $this->postJson('/api/v1/chat/frontend/memory', $payload, $headers)->assertOk()->assertJsonPath('saved', 0);
        $t = $this->postJson('/api/v1/chat/frontend/begin', ['message' => 'Private', 'temporary' => true], $headers)->assertOk()->assertJsonPath('automatic_memory', false);
        $this->postJson('/api/v1/chat/frontend/finish', ['ticket' => $t->json('ticket'), 'content' => 'Temporary', 'status' => 'complete'], $headers)->assertOk();
        $this->postJson('/api/v1/chat/frontend/memory', ['ticket' => $t->json('ticket'), 'memories' => ['Should not be stored']], $headers)->assertStatus(409);
        $this->assertDatabaseCount('memories', 1);
    }

    public function test_local_completed_turn_extracts_memory_but_opt_out_prevents_provider_calls(): void
    {
        $u = $this->user();
        $u->forceFill(['memory_enabled' => true, 'automatic_memory' => true])->save();
        $c = Conversation::create(['user_id' => $u->id, 'title' => 'Preferences']);
        $q = $c->messages()->create(['role' => 'user', 'content' => 'I prefer Bangla']);
        $a = $c->messages()->create(['role' => 'assistant', 'content' => 'Understood', 'status' => 'complete']);
        $m = AiModel::create(['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'test/memory', 'revision' => str_repeat('a', 40), 'filename' => 'test.gguf', 'sha256' => str_repeat('b', 64), 'bytes' => 4, 'license' => 'test', 'enabled' => true, 'status' => 'ready', 'runtime_model' => 'fixture']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '["Prefers Bangla answers"]']]]])]);
        (new RememberConversation($q->id, $a->id, $m->id))->handle();
        $this->assertDatabaseHas('memories', ['user_id' => $u->id, 'content' => 'Prefers Bangla answers']);
        $u->forceFill(['automatic_memory' => false])->save();
        (new RememberConversation($q->id, $a->id, $m->id))->handle();
        Http::assertSentCount(1);
    }
}
