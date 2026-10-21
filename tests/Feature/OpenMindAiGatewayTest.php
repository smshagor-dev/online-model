<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenMindAiGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function account(): User
    {
        $u = User::create(['name' => 'Developer', 'email' => uniqid().'@example.com', 'password' => 'TestPassword123']);
        $u->forceFill(['email_verified_at' => now()])->save();
        return $u;
    }

    public function test_developer_token_can_call_hosted_models_but_not_read_account(): void
    {
        $u = $this->account();
        $token = $u->createToken('developer:Test IDE', ['openmindai:invoke'], now()->addDays(90))->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/openmindai/models')->assertOk()
            ->assertJsonCount(42, 'data')
            ->assertJsonFragment(['id' => 'openmindai-super', 'name' => 'OpenMindAI Super']);
        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/tokens')->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/chat', ['model_id' => 1, 'message' => 'test'])->assertForbidden();
    }

    public function test_developer_key_can_be_generated_and_revoked(): void
    {
        $u = $this->account();
        $session = $u->createToken('web session', ['*'])->plainTextToken;
        $created = $this->withToken($session)->postJson('/api/v1/developer/keys', [
            'name' => 'My IDE', 'password' => 'TestPassword123',
        ])->assertCreated()->assertJsonStructure(['id', 'api_key', 'base_url']);
        $newToken = $created->json('api_key');
        $this->withToken($newToken)->getJson('/api/v1/openmindai/models')->assertOk();
        $this->withToken($session)->deleteJson('/api/v1/developer/keys/'.$created->json('id'))->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/v1/openmindai/models')->assertUnauthorized();
    }

    public function test_chat_only_uses_allowlisted_nvidia_provider_and_records_success(): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([
            'id' => 'completion-1',
            'created' => 123,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Here is your code'], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 12],
        ])]);
        $u = $this->account();
        $token = $u->createToken('developer:editor', ['openmindai:invoke'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/openmindai/chat/completions', [
            'model' => 'openmindai-lite',
            'messages' => [['role' => 'user', 'content' => 'Write a secure PHP function.']],
            'stream' => false,
        ])->assertOk()->assertJsonPath('model', 'openmindai-lite')->assertJsonPath('choices.0.message.content', 'Here is your code');
        Http::assertSent(fn ($request) => $request->url() === 'https://integrate.api.nvidia.com/v1/chat/completions'
            && $request['model'] === 'nvidia/nemotron-3.5-lightning-30b-a3b');
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $u->id, 'credits' => 1]);
        DB::table('hosted_code_agents')->where('slug', 'openmindai-lite')->update(['api_ready' => false]);
        $this->withToken($token)->postJson('/api/v1/openmindai/chat/completions', [
            'model' => 'openmindai-lite', 'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_monthly_credit_limits_reject_excess_requests(): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        Http::fake();
        $u = $this->account();
        $u->forceFill(['monthly_credit_limit' => 0])->save();
        $token = $u->createToken('developer:test', ['openmindai:invoke'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/openmindai/chat/completions', [
            'model' => 'openmindai-super', 'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_first_party_hmac_access_is_verified_and_cannot_be_replayed(): void
    {
        $u = $this->account();
        $secret = 'signing-secret-example-32-bytes-long';
        DB::table('first_party_apps')->insert([
            'app_id' => 'my-first-party-app',
            'name' => 'My App',
            'owner_user_id' => $u->id,
            'encrypted_secret' => Crypt::encryptString($secret),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $path = '/api/v1/first-party/models';
        $message = implode("\n", [$timestamp, $nonce, 'GET', $path, hash('sha256', '')]);
        $headers = [
            'X-OpenMindAI-App' => 'my-first-party-app',
            'X-OpenMindAI-Timestamp' => $timestamp,
            'X-OpenMindAI-Nonce' => $nonce,
            'X-OpenMindAI-Signature' => hash_hmac('sha256', $message, $secret),
        ];
        $this->get($path, $headers)->assertOk()->assertJsonCount(42, 'data');
        $this->get($path, $headers)->assertStatus(409);
        $this->get($path, [...$headers, 'X-OpenMindAI-Nonce' => bin2hex(random_bytes(16))])->assertUnauthorized();
    }
    public function test_all_models_are_visible_even_when_provider_is_not_ready(): void
    {
        $this->assertDatabaseCount('hosted_code_agents', 42);
        $u = $this->account();
        $token = $u->createToken('developer:catalog', ['openmindai:invoke'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()
            ->assertJsonCount(42, 'data')
            ->assertJsonFragment(['id' => 'openmindai-super', 'available' => true]);
        DB::table('hosted_code_agents')->where('slug', 'nvidia-nemotron-3.5-embed-1b')
            ->update(['enabled' => false]);
        $this->withToken($token)->getJson('/api/v1/openmindai/catalog')->assertOk()
            ->assertJsonCount(42, 'data')
            ->assertJsonFragment([
                'id' => 'nvidia-nemotron-3.5-embed-1b',
                'available' => false,
                'path' => null,
            ]);
        $this->withToken($token)->getJson('/api/v1/openmindai/models')->assertOk()
            ->assertJsonCount(42, 'data')
            ->assertJsonFragment(['id' => 'nvidia-nemotron-3.5-embed-1b', 'available' => false]);
    }

    public function test_specialized_json_invoke_requires_admin_verified_configuration_and_tracks_usage(): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        Http::fake(['integrate.api.nvidia.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => [0.5, 0.25]]],
        ])]);
        $u = $this->account();
        $token = $u->createToken('developer:catalog', ['openmindai:invoke'])->plainTextToken;
        $slug = 'nvidia-nemotron-3.5-embed-1b';
        $endpoint = '/api/v1/openmindai/models/'.$slug.'/invoke';
        $input = ['input' => ['input' => 'hello world']];
        $this->withToken($token)->postJson($endpoint, $input)->assertNotFound();
        DB::table('hosted_code_agents')->where('slug', $slug)->update([
            'enabled' => true,
            'api_ready' => true,
            'api_path' => '/v1/embeddings',
            'include_model' => true,
        ]);
        $this->withToken($token)->postJson($endpoint, $input)->assertOk()
            ->assertJsonPath('model', $slug)
            ->assertJsonPath('output.data.0.embedding.0', 0.5);
        Http::assertSent(fn ($r) => $r->url() === 'https://integrate.api.nvidia.com/v1/embeddings'
            && $r['model'] === 'nvidia/nemotron-3.5-embed-1b'
            && $r['input'] === 'hello world');
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $u->id, 'credits' => 1]);
        $this->withToken($token)->postJson('/api/v1/openmindai/chat/completions', [
            'model' => $slug, 'messages' => [['role' => 'user', 'content' => 'hello']],
        ])->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_model_configuration_never_forwards_client_supplied_provider_model(): void
    {
        config(['models.nvidia_key' => 'test-provider-secret-123']);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response(['result' => 'ok'])]);
        $u = $this->account();
        $token = $u->createToken('developer:safe', ['openmindai:invoke'])->plainTextToken;
        $slug = 'nvidia-nemotron-3-embed-1b';
        DB::table('hosted_code_agents')->where('slug', $slug)->update([
            'enabled' => true, 'api_ready' => true, 'api_path' => '/v1/embeddings', 'include_model' => true,
        ]);
        $this->withToken($token)->postJson('/api/v1/openmindai/models/'.$slug.'/invoke', [
            'input' => ['model' => 'another/paid-model', 'input' => 'safe prompt', 'stream' => true],
        ])->assertOk();
        Http::assertSent(fn ($r) => $r['model'] === 'nvidia/nemotron-3-embed-1b'
            && ! isset($r['stream']));
    }

}
