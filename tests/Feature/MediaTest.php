<?php

namespace Tests\Feature;

use App\Jobs\RunMediaTask;
use App\Models\MediaTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Media', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
    }

    private function setupEngine(): User
    {
        config(['media.url' => 'http://local-engine:8090', 'media.key' => str_repeat('x', 32), 'media.kinds' => ['image', 'vision', 'transcription', 'speech', 'video']]);
        Storage::fake('local');
        Queue::fake();
        $user = $this->user();
        Sanctum::actingAs($user);

        return $user;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    }

    public function test_disabled_engine_is_not_advertised_or_queued(): void
    {
        $this->setupEngine();
        config(['media.url' => null]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('media', [])->assertJsonPath('image_generation', false);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Sunrise'])->assertStatus(503);
        Queue::assertNothingPushed();
    }

    public function test_generation_is_queued_and_private_binary_output_is_downloadable(): void
    {
        $this->setupEngine();
        $r = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Sunrise'])->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonMissingPath('input_path');
        $id = $r->json('id');
        Queue::assertPushed(RunMediaTask::class, fn ($j) => $j->taskId === $id && $j->queue === 'media' && $j->connection === 'media');
        Http::fake(['*' => Http::response(['data' => base64_encode($this->png()), 'mime' => 'image/png'])]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertOk()->assertJsonPath('status', 'complete')->assertJsonMissingPath('output_path');
        $output = $this->get('/api/v1/media/'.$id.'/output')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($this->png(), $output->streamedContent());
        Http::assertSent(fn ($r) => $r->url() === 'http://local-engine:8090/run' && $r->hasHeader('Authorization', 'Bearer '.str_repeat('x', 32)));
    }

    public function test_vision_input_is_validated_used_and_erased_after_processing(): void
    {
        $this->setupEngine();
        $this->postJson('/api/v1/media', ['kind' => 'vision', 'prompt' => 'Describe', 'input' => base64_encode('<?php evil ?>')])->assertStatus(422);
        $r = $this->postJson('/api/v1/media', ['kind' => 'vision', 'prompt' => 'Describe', 'input' => base64_encode($this->png())])->assertStatus(202);
        $task = MediaTask::findOrFail($r->json('id'));
        $input = $task->input_path;
        Storage::disk('local')->assertExists($input);
        Http::fake(['*' => Http::response(['text' => 'A white pixel'])]);
        (new RunMediaTask($task->id))->handle();
        $this->assertSame('A white pixel', $task->fresh()->result_text);
        $this->assertNull($task->fresh()->input_path);
        Storage::disk('local')->assertMissing($input);
        Http::assertSent(fn ($r) => $r['input'] === base64_encode($this->png()));
    }

    public function test_other_users_cannot_read_delete_or_download_a_task(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Secret'])->json('id');
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/media/'.$id)->assertNotFound();
        $this->getJson('/api/v1/media/'.$id.'/output')->assertNotFound();
        $this->deleteJson('/api/v1/media/'.$id)->assertNotFound();
        $this->getJson('/api/v1/media')->assertJsonCount(0, 'data');
    }

    public function test_runtime_failure_is_recorded_without_leaking_runtime_secrets(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(['*' => Http::response('SECRET trace', 500)]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertJsonPath('status', 'failed')->assertDontSee('SECRET');
        $this->getJson('/api/v1/media/'.$id.'/output')->assertNotFound();
    }

    public function test_malformed_runtime_binary_is_rejected(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(['*' => Http::response(['data' => base64_encode('<script>evil</script>'), 'mime' => 'image/png'])]);
        (new RunMediaTask($id))->handle();
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed', 'output_path' => null]);
    }

    public function test_deleted_running_task_discards_result(): void
    {
        $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        Http::fake(function () use ($id) {
            MediaTask::find($id)->delete();

            return Http::response(['data' => base64_encode($this->png()), 'mime' => 'image/png']);
        });
        (new RunMediaTask($id))->handle();
        $this->assertDatabaseMissing('media_tasks', ['id' => $id]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pending_task_limit_and_quota_are_enforced(): void
    {
        $this->setupEngine();
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'One'])->assertStatus(202);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Two'])->assertStatus(202);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Three'])->assertStatus(429);
        MediaTask::query()->update(['status' => 'complete']);
        config(['media.storage_bytes' => 100]);
        $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Large'])->assertStatus(422);
    }

    public function test_suspended_users_queued_tasks_do_not_contact_runtime(): void
    {
        $user = $this->setupEngine();
        $id = $this->postJson('/api/v1/media', ['kind' => 'image', 'prompt' => 'Test'])->json('id');
        $user->forceFill(['is_active' => false])->save();
        Http::fake();
        (new RunMediaTask($id))->handle();
        Http::assertNothingSent();
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed']);
    }

    public function test_account_deletion_cleans_private_media(): void
    {
        $user = $this->setupEngine();
        Storage::disk('local')->put('media/test.output', 'private');
        MediaTask::create(['user_id' => $user->id, 'kind' => 'image', 'prompt' => 'Private', 'output_path' => 'media/test.output']);
        $this->deleteJson('/api/v1/me', ['password' => 'StrongPassword123'])->assertNoContent();
        Storage::disk('local')->assertMissing('media/test.output');
        $this->assertDatabaseCount('media_tasks', 0);
    }

    public function test_code_artifacts_are_persisted_but_not_in_list_payloads(): void
    {
        $this->setupEngine();
        config(['media.kinds' => ['code']]);
        $id = $this->postJson('/api/v1/media', ['kind' => 'code', 'prompt' => 'print(1)', 'approved' => true])->assertStatus(202)->json('id');
        Http::fake(['*' => Http::response(['text' => 'hello', 'mime' => 'application/zip', 'data' => 'UEsDBBQAAAAAAOBEU12GphA2BQAAAAUAAAAKAAAAc3Rkb3V0LnR4dGhlbGxvUEsBAhQDFAAAAAAA4ERTXYamEDYFAAAABQAAAAoAAAAAAAAAAAAAAIABAAAAAHN0ZG91dC50eHRQSwUGAAAAAAEAAQA4AAAALQAAAAAA', 'artifacts' => [['name' => 'data.csv', 'type' => 'table', 'rows' => [['x', 'y'], ['1', '2']]]]])]);
        (new RunMediaTask($id))->handle();
        $this->getJson('/api/v1/media/'.$id)->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('artifacts.0.rows.1.1', '2');
        $this->getJson('/api/v1/media')->assertOk()->assertJsonMissingPath('data.0.artifacts');
    }
}
