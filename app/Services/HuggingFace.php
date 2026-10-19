<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Support\Facades\Http;

class HuggingFace
{
    public function metadata(string $repository, ?string $revision = null): array
    {
        abort_unless(preg_match('~^[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+$~D', $repository), 422, 'Invalid repository.');
        $request = Http::acceptJson()->connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false]);
        if (config('models.hf_token')) {
            $request = $request->withToken(config('models.hf_token'));
        }
        $path = 'https://huggingface.co/api/models/'.$repository;
        if ($revision !== null) {
            abort_unless(preg_match('/^[a-f0-9]{40}$/D', $revision), 422);
            $path .= '/revision/'.$revision;
        }
        $response = $request->get($path, ['blobs' => 'true']);
        abort_unless($response->successful(), 422, 'Cannot read Hugging Face repository. Check access and license acceptance.');
        $data = $response->json();
        abort_unless(is_array($data) && preg_match('/^[a-f0-9]{40}$/D', $data['sha'] ?? ''), 422, 'Missing pinned revision.');

        return $data;
    }

    public function search(string $query): array
    {
        $response = Http::acceptJson()->connectTimeout(10)->timeout(20)->withOptions(['allow_redirects' => false])->get('https://huggingface.co/api/models', ['search' => $query, 'filter' => 'gguf', 'sort' => 'downloads', 'direction' => -1, 'limit' => 20]);
        abort_unless($response->successful(), 502, 'Hugging Face search unavailable.');

        return $response->json();
    }

    public function import(array $input): AiModel
    {
        $meta = $this->metadata($input['repository'], $input['revision']);
        $file = collect($meta['siblings'] ?? [])->firstWhere('rfilename', $input['filename']);
        abort_unless($file && preg_match('~^[A-Za-z0-9_./-]+\.gguf$~D', $input['filename']) && ! str_contains($input['filename'], '..'), 422, 'Choose a valid GGUF file.');
        $hash = $file['lfs']['sha256'] ?? null;
        $size = $file['lfs']['size'] ?? $file['size'] ?? 0;
        abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) && $size > 0 && $size <= config('models.max_bytes'), 422, 'Verified file hash/size unavailable or model exceeds size limit.');
        $license = $meta['cardData']['license'] ?? 'review-required';
        if (! is_string($license)) {
            $license = 'review-required';
        }

        return AiModel::firstOrCreate(['repository' => $input['repository'], 'revision' => $meta['sha'], 'filename' => $input['filename']], ['name' => $input['name'], 'family' => $input['family'], 'sha256' => $hash, 'bytes' => $size, 'license' => $license]);
    }
}
