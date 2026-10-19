<?php

namespace App\Services;

use App\Models\ModelBundle;

class MediaModelManifest
{
    public function inspect(string $repository, ?string $revision = null): array
    {
        $metadata = app(HuggingFace::class)->metadata($repository, $revision);
        $files = [];
        $bytes = 0;
        foreach ($metadata['siblings'] ?? [] as $file) {
            $name = $file['rfilename'] ?? '';
            if (strtolower($name) === 'openmindai-source.json') {
                continue;
            }
            if (! preg_match('/\.(safetensors|json|txt|model|tiktoken|vocab|merges|yaml|yml)$/iD', $name)) {
                continue;
            }
            abort_unless(strlen($name) <= 240 && preg_match('~^[A-Za-z0-9_./-]+$~D', $name) && ! str_contains($name, '..') && ! str_starts_with($name, '/'), 422, 'Unsafe snapshot filename.');
            $size = $file['lfs']['size'] ?? $file['size'] ?? null;
            $hash = $file['lfs']['sha256'] ?? $file['blobId'] ?? $file['blob_id'] ?? null;
            $algorithm = isset($file['lfs']) ? 'sha256' : 'git-sha1';
            abort_unless(is_numeric($size) && $size >= 0 && is_string($hash) && preg_match($algorithm === 'sha256' ? '/^[a-f0-9]{64}$/D' : '/^[a-f0-9]{40}$/D', $hash), 422, 'Verified snapshot file metadata unavailable.');
            $bytes += (int) $size;
            abort_if($bytes > config('models.max_bytes'), 422, 'Snapshot exceeds configured model size limit.');
            $files[] = ['filename' => $name, 'bytes' => (int) $size, 'checksum' => $hash, 'algorithm' => $algorithm];
        }
        abort_unless(collect($files)->contains(fn ($file) => str_ends_with(strtolower($file['filename']), '.safetensors')), 422, 'A compatible safetensors snapshot is required.');
        $license = $metadata['cardData']['license'] ?? 'review-required';

        return ['revision' => $metadata['sha'], 'files' => $files, 'bytes' => $bytes, 'license' => is_string($license) ? mb_substr($license, 0, 160) : 'review-required'];
    }

    public function import(array $input): ModelBundle
    {
        $manifest = $this->inspect($input['repository'], $input['revision']);

        return ModelBundle::firstOrCreate(['repository' => $input['repository'], 'revision' => $manifest['revision'], 'purpose' => $input['purpose']], ['name' => $input['name'], ...$manifest]);
    }
}
