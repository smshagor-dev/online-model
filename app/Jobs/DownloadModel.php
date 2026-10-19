<?php

namespace App\Jobs;

use App\Models\AiModel;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
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
            $used = array_sum(array_map('filesize', glob($root.'/*.gguf') ?: []));
            if ($used + $model->bytes > config('models.storage_budget') || disk_free_space($root) < $model->bytes + 104857600) {
                throw new \RuntimeException('Insufficient model storage.');
            }
            $url = 'https://huggingface.co/'.$model->repository.'/resolve/'.$model->revision.'/'.implode('/', array_map('rawurlencode', explode('/', $model->filename)));
            $headers = [];
            if (config('models.hf_token')) {
                $headers['Authorization'] = 'Bearer '.config('models.hf_token');
            }
            // Follow only HTTPS Hugging Face/CDN redirects; credentials never follow a redirect.
            for ($hop = 0; $hop < 8; $hop++) {
                $head = Http::withHeaders($headers)->connectTimeout(15)->timeout(30)->withOptions(['allow_redirects' => false])->head($url);
                if (! in_array($head->status(), [301, 302, 303, 307, 308], true)) {
                    break;
                }
                $next = (string) UriResolver::resolve(new Uri($url), new Uri($head->header('Location')));
                $host = parse_url($next, PHP_URL_HOST);
                $scheme = parse_url($next, PHP_URL_SCHEME);
                if (! $host || $scheme !== 'https' || ! $this->allowedHost($host) || parse_url($next, PHP_URL_USER) || parse_url($next, PHP_URL_PORT)) {
                    throw new \RuntimeException('Unsafe download redirect.');
                }
                $url = $next;
                $headers = [];
            }
            $response = Http::withHeaders($headers)->connectTimeout(15)->timeout(7000)->withOptions(['allow_redirects' => false, 'sink' => $part, 'progress' => function ($total, $downloaded) use ($model) {
                if ($downloaded > $model->bytes || $total > $model->bytes) {
                    throw new \RuntimeException('Download exceeded expected size.');
                }
            }])->get($url);
            if (! $response->successful() || ! is_file($part) || filesize($part) !== $model->bytes || ! hash_equals($model->sha256, hash_file('sha256', $part))) {
                throw new \RuntimeException('Download integrity verification failed.');
            }
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

    private function allowedHost(string $host): bool
    {
        foreach (['huggingface.co', 'hf.co', 'xethub.hf.co', 'cloudfront.net', 'amazonaws.com'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function failed(?Throwable $error): void
    {
        AiModel::whereKey($this->modelId)->whereIn('status', ['queued', 'downloading'])->update(['status' => 'failed', 'download_error' => 'Worker stopped. Retry the download after checking the worker.']);
    }
}
