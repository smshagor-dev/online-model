<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $u = User::create(['name' => 'Live', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($u);

        return $u;
    }

    private function enable(): void
    {
        config(['media.live' => true, 'media.url' => 'https://private-runtime.example', 'media.key' => 'private-runtime-secret', 'media.kinds' => ['transcription', 'vision'], 'media.live_daily_limit' => 2]);
    }

    private function wav(): string
    {
        return base64_encode('RIFF'.pack('V', 2036).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', 2000).str_repeat("\0", 2000));
    }

    public function test_disabled_and_unconsented_live_sessions_are_rejected(): void
    {
        $this->user();
        $this->postJson('/api/v1/live/start', ['consent' => true])->assertStatus(503);
        $this->enable();
        $this->postJson('/api/v1/live/start')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_live_input_is_private_ephemeral_and_bound_to_user_and_session(): void
    {
        $owner = $this->user();
        $this->enable();
        Http::fake(['private-runtime.example/*' => Http::response(['text' => 'Spoken question'])]);
        $session = $this->postJson('/api/v1/live/start', ['consent' => true])->assertOk()->json('session');
        $input = ['session' => $session, 'kind' => 'transcription', 'input' => $this->wav()];
        $this->postJson('/api/v1/live/input', $input)->assertOk()->assertJsonPath('text', 'Spoken question');
        Http::assertSent(fn ($r) => $r->url() === 'https://private-runtime.example/live' && $r->hasHeader('Authorization', 'Bearer private-runtime-secret') && $r['kind'] === 'transcription');
        $this->assertDatabaseCount('media_tasks', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->user();
        $this->postJson('/api/v1/live/input', $input)->assertStatus(410);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/live/stop', ['session' => $session])->assertNoContent();
        $this->postJson('/api/v1/live/input', $input)->assertStatus(410);
    }

    public function test_replacement_and_daily_limit_do_not_charge_each_internal_input(): void
    {
        $this->user();
        $this->enable();
        Http::fake(['private-runtime.example/*' => Http::response(['text' => 'Hello'])]);
        $old = $this->postJson('/api/v1/live/start', ['consent' => true])->assertOk()->json('session');
        $current = $this->postJson('/api/v1/live/start', ['consent' => true])->assertOk()->json('session');
        $this->postJson('/api/v1/live/stop', ['session' => $old])->assertStatus(410);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/live/input', ['session' => $current, 'kind' => 'transcription', 'input' => $this->wav()])->assertOk();
        }
        $this->postJson('/api/v1/live/start', ['consent' => true])->assertStatus(429);
        $this->postJson('/api/v1/live/input', ['session' => $current, 'kind' => 'transcription', 'input' => base64_encode('not audio')])->assertUnprocessable();
        $this->postJson('/api/v1/live/stop', ['session' => $current])->assertNoContent();
    }

    public function test_expired_session_and_unsafe_runtime_responses_are_rejected(): void
    {
        $this->user();
        $this->enable();
        $session = $this->postJson('/api/v1/live/start', ['consent' => true])->assertOk()->json('session');
        Http::fake(['private-runtime.example/*' => Http::response('<html>SECRET</html>', 200)]);
        $this->postJson('/api/v1/live/input', ['session' => $session, 'kind' => 'transcription', 'input' => $this->wav()])->assertStatus(502)->assertDontSee('SECRET');
        $this->travel(121)->minutes();
        $this->postJson('/api/v1/live/input', ['session' => $session, 'kind' => 'transcription', 'input' => $this->wav()])->assertStatus(410);
    }
}
