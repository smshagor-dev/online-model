<?php

namespace App\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

class HuggingFaceFile
{
    public function download(string $repository, string $revision, string $filename, int $bytes, string $checksum, string $part, string $algorithm = 'sha256'): void
    {
        $url = 'https://huggingface.co/'.$repository.'/resolve/'.$revision.'/'.implode('/', array_map('rawurlencode', explode('/', $filename)));
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
        $response = Http::withHeaders($headers)->connectTimeout(15)->timeout(7000)->withOptions(['allow_redirects' => false, 'sink' => $part, 'progress' => function ($total, $downloaded) use ($bytes) {
            if ($downloaded > $bytes || $total > $bytes) {
                throw new \RuntimeException('Download exceeded expected size.');
            }
        }])->get($url);
        if (! $response->successful() || ! is_file($part) || filesize($part) !== $bytes || ! hash_equals($checksum, $this->hash($part, $algorithm))) {
            throw new \RuntimeException('Download integrity verification failed.');
        }
    }

    private function hash(string $path, string $algorithm): string
    {
        if ($algorithm === 'sha256') {
            return hash_file('sha256', $path);
        }
        if ($algorithm !== 'git-sha1') {
            throw new \RuntimeException('Unsupported checksum.');
        }
        $hash = hash_init('sha1');
        hash_update($hash, 'blob '.filesize($path)."\0");
        hash_update_file($hash, $path);

        return hash_final($hash);
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
}
