<?php

namespace Tests\Feature;

use App\Jobs\RunCloudImage;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\CloudImageEngine;
use App\Services\CloudImagePrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CloudImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::create(['name' => 'Image Test', 'email' => uniqid().'@example.test', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($user);
        config(['cloud_images.enabled' => true, 'cloud_images.key' => 'server-provider-test-token']);

        return $user;
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=');
    }

    public function test_images_are_queued_with_ratios_variations_and_no_frontend_provider_call(): void
    {
        $this->signIn();
        Queue::fake();
        Http::fake();
        $response = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'A futuristic city', 'count' => 3, 'aspect_ratio' => '16:9', 'seed' => 100,
        ])->assertStatus(202)->assertJsonPath('status', 'queued')->assertJsonCount(3, 'ids');
        $this->assertDatabaseCount('media_tasks', 3);
        foreach ($response->json('ids') as $index => $id) {
            $task = MediaTask::findOrFail($id);
            $this->assertSame('queued', $task->status);
            $this->assertSame('16:9', $task->cloud_options['aspect_ratio']);
            $this->assertSame(100 + $index, $task->cloud_options['seed']);
        }
        Queue::assertPushed(RunCloudImage::class, 3);
        Http::assertNothingSent();
    }

    public function test_quota_and_input_guards_reject_batch_before_dispatch(): void
    {
        $this->signIn();
        Queue::fake();
        config(['media.image_daily_limit' => 2]);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'count' => 3,
        ])->assertStatus(429);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'mode' => 'edit',
        ])->assertStatus(503);
        $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Forest', 'aspect_ratio' => '1:3',
        ])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('media_tasks', 0);
    }

    public function test_worker_validates_output_and_saves_private_image_and_credit(): void
    {
        $user = $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $result = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Sunrise over forest'])
            ->assertStatus(202)->json();
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'artifacts' => [['base64' => base64_encode($this->png()), 'finishReason' => 'SUCCESS']],
        ])]);
        (new RunCloudImage($result['id']))->handle(app(CloudImageEngine::class));
        $task = MediaTask::findOrFail($result['id']);
        $this->assertSame('complete', $task->status);
        $this->assertSame('image/png', $task->output_mime);
        Storage::disk('local')->assertExists($task->output_path);
        $this->assertDatabaseHas('api_credit_usage', ['user_id' => $user->id, 'credits' => 1]);
        $this->get('/api/v1/media/'.$task->id.'/output')->assertOk()->assertHeader('Content-Type', 'image/png');
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.fast_endpoint')
            && $request['width'] === 1024 && $request['samples'] === 1);
    }

    public function test_failed_provider_request_is_not_counted_as_success(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $id = $this->postJson('/api/v1/media/cloud-image', ['prompt' => 'Mountain'])->json('id');
        Http::fake(['ai.api.nvidia.com/*' => Http::response(['message' => 'rate limited'], 429)]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        $this->assertDatabaseHas('media_tasks', ['id' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('api_credit_usage', 0);
    }

    public function test_nonsquare_generation_uses_aspect_ratio_provider_not_unsupported_flux_dimensions(): void
    {
        $this->signIn();
        Storage::fake('local');
        Queue::fake();
        $id = $this->postJson('/api/v1/media/cloud-image', [
            'prompt' => 'Sea landscape', 'aspect_ratio' => '16:9',
        ])->json('id');
        Http::fake(['ai.api.nvidia.com/*' => Http::response([
            'artifacts' => [['base64' => base64_encode($this->png())]],
        ])]);
        (new RunCloudImage($id))->handle(app(CloudImageEngine::class));
        Http::assertSent(fn ($request) => $request->url() === config('cloud_images.ratio_endpoint')
            && $request['aspect_ratio'] === '16:9' && ! isset($request['width']));
    }

    public function test_language_bridge_preserves_source_and_falls_back_when_unavailable(): void
    {
        $this->signIn();
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'A blue futuristic robot in Dhaka']]],
        ])]);
        $source = 'dhaka te ekta nil robot er chobi banao';
        $normalized = app(CloudImagePrompt::class)->normalize($source);
        $this->assertSame('A blue futuristic robot in Dhaka', $normalized);
        Http::fake(['integrate.api.nvidia.com/*' => Http::response([], 503)]);
        $this->assertSame($source, app(CloudImagePrompt::class)->normalize($source));
    }

    public function test_unconfigured_editing_is_not_advertised_and_urls_are_operator_selected(): void
    {
        $this->signIn();
        $this->getJson('/api/v1/media/image-capabilities')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('image_editing', false)
            ->assertJsonPath('image_masking', false);
        $this->assertFalse(CloudImageEngine::safeNimUrl('http://127.0.0.1/v1/images/edits'));
        $this->assertFalse(CloudImageEngine::safeNimUrl('https://example.org/other/path'));
        $this->assertTrue(CloudImageEngine::safeNimUrl('https://image-worker.example.org/v1/images/edits'));
    }
}
