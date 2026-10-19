<?php

namespace Tests\Feature;

use App\Jobs\DownloadModelBundle;
use App\Models\ModelBundle;
use App\Models\User;
use App\Services\MediaModelManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModelBundleTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/openmind-bundle-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function metadata(): array
    {
        return ['sha' => str_repeat('a', 40), 'cardData' => ['license' => 'apache-2.0'], 'siblings' => [
            ['rfilename' => 'model.safetensors', 'lfs' => ['sha256' => hash('sha256', 'SAFE'), 'size' => 4]],
            ['rfilename' => 'config.json', 'size' => 2, 'blobId' => sha1("blob 2\0{}")],
            ['rfilename' => 'model.py', 'size' => 4, 'blobId' => str_repeat('b', 40)],
        ]];
    }

    private function bundle(): ModelBundle
    {
        Http::fake(['huggingface.co/api/models/*' => Http::response($this->metadata())]);

        return app(MediaModelManifest::class)->import(['name' => 'Image snapshot', 'repository' => 'owner/model', 'revision' => str_repeat('a', 40), 'purpose' => 'image']);
    }

    public function test_snapshot_import_and_download_verify_safetensors_and_git_blob_files(): void
    {
        $bundle = $this->bundle();
        $this->assertCount(2, $bundle->files);
        $this->assertSame(6, $bundle->bytes);
        $bundle->update(['status' => 'queued']);
        Http::fake(fn ($r) => Http::response($r->method() === 'HEAD' ? '' : (str_ends_with($r->url(), 'config.json') ? '{}' : 'SAFE')));
        (new DownloadModelBundle($bundle->id))->handle();
        $this->assertSame('ready', $bundle->fresh()->status);
        $path = $this->root.'/app/models/bundles/'.$bundle->id;
        $this->assertFileExists($path.'/model.safetensors');
        $this->assertFileExists($path.'/openmindai-source.json');
        $this->assertFileDoesNotExist($path.'/model.py');
        $this->assertSame(str_repeat('a', 40), json_decode(File::get($path.'/openmindai-source.json'), true)['revision']);
    }

    public function test_bad_snapshot_integrity_never_becomes_ready(): void
    {
        $bundle = $this->bundle();
        $bundle->update(['status' => 'queued']);
        Http::fake(fn ($r) => Http::response($r->method() === 'HEAD' ? '' : 'BAD!'));
        try {
            (new DownloadModelBundle($bundle->id))->handle();
            $this->fail('Corruption accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $bundle->fresh()->status);
        }
        $this->assertDirectoryDoesNotExist($this->root.'/app/models/bundles/'.$bundle->id);
        $this->assertDirectoryDoesNotExist($this->root.'/app/models/bundles/'.$bundle->id.'.part');
    }

    public function test_snapshot_metadata_rejects_path_traversal(): void
    {
        $meta = $this->metadata();
        $meta['siblings'][0]['rfilename'] = '../escape.safetensors';
        Http::fake(['*' => Http::response($meta)]);
        $this->expectException(HttpException::class);
        app(MediaModelManifest::class)->inspect('owner/model');
    }

    public function test_snapshot_and_gguf_downloads_share_one_storage_budget(): void
    {
        $bundle = $this->bundle();
        File::ensureDirectoryExists($this->root.'/app/models', 0700, true);
        File::put($this->root.'/app/models/old.gguf', 'GGUF');
        config(['models.storage_budget' => 1048580]);
        $bundle->update(['status' => 'queued']);
        try {
            (new DownloadModelBundle($bundle->id))->handle();
            $this->fail('Shared budget ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('failed', $bundle->fresh()->status);
        }
        $this->assertFileExists($this->root.'/app/models/old.gguf');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/resolve/'));
    }

    public function test_only_verified_admin_can_manage_snapshot_downloads(): void
    {
        Queue::fake();
        $u = User::create(['name' => 'Admin', 'email' => 'bundle@example.org', 'password' => 'StrongPassword123', 'is_admin' => true]);
        $u->forceFill(['is_admin' => true])->save();
        $this->actingAs($u)->get('/admin/model-bundles')->assertForbidden();
        $this->withSession(['admin_verified' => true])->get('/admin/model-bundles')->assertOk();
        Http::fake(['huggingface.co/api/models/*' => Http::response($this->metadata())]);
        $this->get('/admin/model-bundles/inspect?repository=owner/model&purpose=image')->assertOk()->assertSee('Review media snapshot');
        $this->post('/admin/model-bundles', ['name' => 'Image', 'repository' => 'owner/model', 'revision' => str_repeat('a', 40), 'purpose' => 'image', 'license_accepted' => 1])->assertRedirect('/admin/model-bundles');
        $bundle = ModelBundle::firstOrFail();
        $this->post('/admin/model-bundles/'.$bundle->id.'/download')->assertRedirect();
        Queue::assertPushed(DownloadModelBundle::class);
        $this->post('/admin/model-bundles/'.$bundle->id.'/download')->assertStatus(409);
    }
}
