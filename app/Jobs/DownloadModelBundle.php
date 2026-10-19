<?php

namespace App\Jobs;

use App\Models\ModelBundle;
use App\Services\HuggingFaceFile;
use App\Services\ModelStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

class DownloadModelBundle implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 0;

    public int $deadline;

    public bool $failOnTimeout = true;

    public function __construct(public int $bundleId)
    {
        $this->deadline = time() + 86400;
    }

    public function retryUntil(): \DateTime
    {
        return (new \DateTime)->setTimestamp($this->deadline);
    }

    public function handle(): void
    {
        $bundle = ModelBundle::findOrFail($this->bundleId);
        if ($bundle->status !== 'queued') {
            return;
        }
        $root = storage_path('app/models');
        File::ensureDirectoryExists($root, 0700, true);
        $guard = fopen($root.'/.download.lock', 'c');
        if (! flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            $this->release(60);

            return;
        }
        $staging = $root.'/bundles/'.$bundle->id.'.part';
        $final = $root.'/bundles/'.$bundle->id;
        try {
            $bundle->update(['status' => 'downloading', 'download_error' => null]);
            File::deleteDirectory($staging);
            if (is_dir($final) || $bundle->bytes > config('models.max_bytes') || app(ModelStorage::class)->used($root) + $bundle->bytes + 1048576 > config('models.storage_budget') || disk_free_space($root) < $bundle->bytes + 104857600) {
                throw new \RuntimeException('Insufficient model storage or existing destination.');
            }
            File::ensureDirectoryExists($staging, 0700, true);
            foreach ($bundle->files as $entry) {
                $path = $staging.'/'.$entry['filename'];
                File::ensureDirectoryExists(dirname($path), 0700, true);
                app(HuggingFaceFile::class)->download($bundle->repository, $bundle->revision, $entry['filename'], $entry['bytes'], $entry['checksum'], $path, $entry['algorithm']);
                chmod($path, 0600);
            }
            File::put($staging.'/openmindai-source.json', json_encode(['repository' => $bundle->repository, 'revision' => $bundle->revision, 'license_accepted' => true, 'verified_manifest' => $bundle->files], JSON_THROW_ON_ERROR));
            chmod($staging.'/openmindai-source.json', 0600);
            if (! rename($staging, $final)) {
                throw new \RuntimeException('Cannot finalize snapshot.');
            }
            $bundle->update(['status' => 'ready']);
        } catch (Throwable $e) {
            File::deleteDirectory($staging);
            $this->failed($e);
            throw new \RuntimeException('Snapshot download failed. See admin status.');
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    public function failed(?Throwable $error): void
    {
        ModelBundle::whereKey($this->bundleId)->whereIn('status', ['queued', 'downloading'])->update(['status' => 'failed', 'download_error' => 'Snapshot could not be verified/downloaded. Check access, license, checksums, disk and worker.']);
    }
}
