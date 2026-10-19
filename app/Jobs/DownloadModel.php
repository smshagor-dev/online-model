<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Services\HuggingFaceFile;
use App\Services\ModelStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DownloadModel implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 0;

    public bool $failOnTimeout = true;

    public int $deadline;

    public function __construct(public int $modelId)
    {
        $this->deadline = time() + 86400;
    }

    public function retryUntil(): \DateTime
    {
        return (new \DateTime)->setTimestamp($this->deadline);
    }

    public function handle(): void
    {
        $model = AiModel::findOrFail($this->modelId);
        if ($model->status !== 'queued') {
            return;
        }
        $root = storage_path('app/models');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        chmod($root, 0700);
        $guard = fopen($root.'/.download.lock', 'c');
        if (! flock($guard, LOCK_EX | LOCK_NB)) {
            fclose($guard);
            $this->release(60);

            return;
        }
        $part = $root.'/'.$model->id.'.part';
        $final = $root.'/'.$model->id.'.gguf';
        try {
            $model->update(['status' => 'downloading', 'download_error' => null]);
            if ($model->bytes > config('models.max_bytes')) {
                throw new \RuntimeException('Size limit exceeded.');
            }
            $used = app(ModelStorage::class)->used($root);
            if ($used + $model->bytes > config('models.storage_budget') || disk_free_space($root) < $model->bytes + 104857600) {
                throw new \RuntimeException('Insufficient model storage.');
            }
            app(HuggingFaceFile::class)->download($model->repository, $model->revision, $model->filename, $model->bytes, $model->sha256, $part);
            if (! rename($part, $final)) {
                throw new \RuntimeException('Cannot finalize model file.');
            }chmod($final, 0600);
            $model->update(['status' => 'ready', 'download_error' => null]);
        } catch (Throwable $e) {
            @unlink($part);
            $model->update(['status' => 'failed', 'download_error' => 'Download failed. Check access, license, disk quota, and model integrity.']);
            throw new \RuntimeException('Model download failed. See model status.');
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    public function failed(?Throwable $error): void
    {
        AiModel::whereKey($this->modelId)->whereIn('status', ['queued', 'downloading'])->update(['status' => 'failed', 'download_error' => 'Worker stopped. Retry the download after checking the worker.']);
    }
}
