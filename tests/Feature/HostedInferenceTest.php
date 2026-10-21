<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HostedInferenceTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $user = User::create(['name' => 'Media', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($user);
        config(['models.frontend_chat_key' => str_repeat('x', 32), 'models.nvidia_key' => 'backend-only-provider-key']);
    }

    private function bridge(): static
    {
        return $this->withHeaders(['X-Chat-Bridge-Key' => str_repeat('x', 32)]);
    }

    public function test_backend_secret_is_required_even_for_a_logged_in_user(): void
    {
        $this->login();
        Http::fake();
        $this->postJson('/api/v1/provider/invoke', [
            'service' => 'image', 'payload' => ['prompt' => 'Sunset'],
        ])->assertForbidden();
        Http::assertNothingSent();

        $this->bridge();
        config(['models.nvidia_key' => '']);
        $this->postJson('/api/v1/provider/invoke', [
            'service' => 'image', 'payload' => ['prompt' => 'Sunset'],
        ])->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_image_model_parameters_cannot_be_overridden_by_frontend(): void
    {
        $this->login();
        Http::fake(['https://ai.api.nvidia.com/*' => Http::response(['artifacts' => [['base64' => 'test']]], 200)]);
        $this->bridge()->postJson('/api/v1/provider/invoke', [
            'service' => 'image',
            'payload' => [
                'prompt' => 'Sunset', 'height' => 4096,
                'url' => 'https://untrusted.invalid/', 'model' => 'foreign/model',
            ],
        ])->assertOk()->assertJsonPath('artifacts.0.base64', 'test');
        Http::assertSent(fn ($r) => $r->url() === 'https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell'
            && $r['prompt'] === 'Sunset' && $r['height'] === 1024
            && ! isset($r['url']) && ! isset($r['model'])
            && $r->hasHeader('Authorization', 'Bearer backend-only-provider-key'));
    }

    public function test_chat_models_must_be_enabled_in_admin_catalog(): void
    {
        $this->login();
        Http::fake(['https://integrate.api.nvidia.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello']]],
        ])]);
        $this->bridge()->postJson('/api/v1/provider/invoke', [
            'service' => 'chat', 'payload' => [
                'model' => 'attacker/model', 'messages' => [['role' => 'user', 'content' => 'Hi']],
            ],
        ])->assertForbidden();
        Http::assertNothingSent();
        DB::table('hosted_code_agents')->where('slug', 'openmindai-lite')
            ->update(['enabled' => true, 'api_ready' => true]);
        $this->postJson('/api/v1/provider/invoke', [
            'service' => 'chat', 'payload' => [
                'model' => 'nvidia/nemotron-3.5-lightning-30b-a3b',
                'messages' => [['role' => 'user', 'content' => 'Hi']],
            ],
        ])->assertOk()->assertJsonPath('choices.0.message.content', 'Hello');
        Http::assertSentCount(1);
    }

    public function test_laravel_selects_speech_voice_not_untrusted_caller(): void
    {
        $this->login();
        $wave = 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 0);
        Http::fake(['*' => Http::response($wave, 200, ['Content-Type' => 'audio/wav'])]);
        $this->bridge()->postJson('/api/v1/provider/invoke', [
            'service' => 'speech', 'payload' => [
                'text' => 'Bonjour', 'language' => 'fr-FR', 'voice' => 'evil',
            ],
        ])->assertOk()->assertHeader('Content-Type', 'audio/wav');
        // Speech uses a multipart request: inspect the serialized fields rather
        // than treating the body as JSON key/value pairs.
        Http::assertSent(fn ($r) => str_contains($r->body(), 'name="language"')
            && str_contains($r->body(), 'fr-FR')
            && str_contains($r->body(), 'name="voice"')
            && str_contains($r->body(), 'Magpie-Multilingual.FR-FR.Louise'));
        $this->postJson('/api/v1/provider/invoke', [
            'service' => 'speech', 'payload' => ['text' => 'Bonjour', 'language' => 'xx-XX'],
        ])->assertStatus(422);
        Http::assertSentCount(1);
    }
}
