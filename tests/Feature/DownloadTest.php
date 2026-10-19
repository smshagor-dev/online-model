<?php

namespace Tests\Feature;

use App\Jobs\DownloadModel;
use App\Models\AiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/online-model-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/app/models', 0700, true);
        $this->app->useStoragePath($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/app/models/*') ?: [] as $file) {
            unlink($file);
        }@unlink($this->root.'/app/models/.download.lock');
        @rmdir($this->root.'/app/models');
        @rmdir($this->root.'/app');
        @rmdir($this->root);
        parent::tearDown();
    }

    private function model(string $hash): AiModel
    {
        return AiModel::create(['name' => 'Q', 'family' => 'Qwen3', 'repository' => 'owner/repo', 'revision' => str_repeat('a', 40), 'filename' => 'm.gguf', 'sha256' => $hash, 'bytes' => 4, 'license' => 'apache', 'status' => 'queued']);
    }

    public function test_download_requires_checksum_before_ready(): void
    {
        $m = $this->model(hash('sha256', 'GGUF'));
        Http::fake(function ($request) {
            return Http::response($request->method() === 'GET' ? 'GGUF' : '', 200);
        });
        (new DownloadModel($m->id))->handle();
        $this->assertSame('ready', $m->fresh()->status);
        $this->assertFileExists($this->root.'/app/models/1.gguf');
    }

    public function test_corrupt_download_is_removed_and_not_enabled(): void
    {
        $m = $this->model(hash('sha256', 'good'));
        Http::fake(function ($request) {
            return Http::response($request->method() === 'GET' ? 'bad!' : '', 200);
        });
        try {
            (new DownloadModel($m->id))->handle();
            $this->fail('Corrupt download accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $m->fresh()->status);
            $this->assertFileDoesNotExist($this->root.'/app/models/1.part');
            $this->assertFileDoesNotExist($this->root.'/app/models/1.gguf');
        }
    }

    public function test_hugging_face_credentials_do_not_follow_a_cdn_redirect(): void
    {
        config(['models.hf_token' => 'private-hf-token']);
        $m = $this->model(hash('sha256', 'GGUF'));
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://huggingface.co/')) {
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer private-hf-token'));

                return Http::response('', 302, ['Location' => 'https://cdn-lfs.hf.co/model']);
            }
            $this->assertFalse($request->hasHeader('Authorization'));

            return Http::response($request->method() === 'GET' ? 'GGUF' : '', 200);
        });
        (new DownloadModel($m->id))->handle();
        $this->assertSame('ready', $m->fresh()->status);
    }

    public function test_redirect_to_untrusted_host_is_rejected(): void
    {
        $m = $this->model(hash('sha256', 'GGUF'));
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret'])]);
        try {
            (new DownloadModel($m->id))->handle();
            $this->fail('Unsafe redirect accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $m->fresh()->status);
            Http::assertSentCount(1);
        }
    }
}
