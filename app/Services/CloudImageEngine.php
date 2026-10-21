<?php

namespace App\Services;

use App\Models\MediaTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CloudImageEngine
{
    private const RATIOS = ['1:1', '16:9', '9:16', '5:4', '4:5', '3:2', '2:3'];

    public static function ratios(): array
    {
        return self::RATIOS;
    }

    public static function safeNimUrl(?string $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        // The URL is set by the operator, never accepted from a user request.
        return ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment']) && ! isset($parts['query'])
            && in_array($parts['path'] ?? '', ['/v1/images/edits', '/v1/infer'], true);
    }

    public function options(string $mode, string $ratio): array
    {
        if (! config('cloud_images.enabled') || strlen((string) config('cloud_images.key')) < 12) {
            abort(503, 'Cloud image creation is not configured.');
        }
        abort_unless(in_array($ratio, self::RATIOS, true), 422, 'Unsupported image aspect ratio.');
        if (in_array($mode, ['edit', 'reference'], true)) {
            abort_unless(self::safeNimUrl(config('cloud_images.edit_url')) &&
                strlen((string) config('cloud_images.edit_key')) >= 12, 503,
                'Image editing requires a configured private NIM image editor.');
        } elseif (in_array($mode, ['inpaint', 'outpaint'], true)) {
            abort_unless(self::safeNimUrl(config('cloud_images.mask_url')) &&
                strlen((string) config('cloud_images.mask_key')) >= 12 &&
                (string) config('cloud_images.mask_model') !== '', 503,
                'Inpainting and outpainting require a compatible private masking NIM.');
        }
        return ['mode' => $mode, 'aspect_ratio' => $ratio];
    }

    public function render(MediaTask $task): array
    {
        $options = $task->cloud_options ?? [];
        $mode = $options['mode'] ?? 'generate';
        $ratio = $options['aspect_ratio'] ?? '1:1';
        $prompt = app(CloudImagePrompt::class)->normalize($task->prompt);
        $seed = (int) ($options['seed'] ?? 0);
        $headers = ['Accept' => 'application/json'];
        if ($mode === 'generate') {
            if ($ratio === '1:1') {
                $url = config('cloud_images.fast_endpoint');
                $model = config('cloud_images.fast_model');
                $payload = [
                    'prompt' => $prompt, 'mode' => 'base',
                    'cfg_scale' => 0, 'height' => 1024, 'width' => 1024,
                    'samples' => 1, 'seed' => $seed, 'steps' => 4,
                ];
            } else {
                $url = config('cloud_images.ratio_endpoint');
                $model = config('cloud_images.ratio_model');
                $payload = [
                    'prompt' => $prompt, 'model' => 'sd3',
                    'mode' => 'text-to-image', 'aspect_ratio' => $ratio,
                    'output_format' => 'jpeg', 'cfg_scale' => 5,
                    'seed' => $seed, 'steps' => 30,
                ];
            }
            $key = config('cloud_images.key');
        } else {
            $masked = in_array($mode, ['inpaint', 'outpaint'], true);
            $url = config($masked ? 'cloud_images.mask_url' : 'cloud_images.edit_url');
            $key = config($masked ? 'cloud_images.mask_key' : 'cloud_images.edit_key');
            $model = config($masked ? 'cloud_images.mask_model' : 'cloud_images.edit_model');
            if (! self::safeNimUrl($url)) {
                throw new RuntimeException('The configured image-edit endpoint is invalid.');
            }
            $source = $task->input_path ? Storage::disk('local')->get($task->input_path) : null;
            if (! $source || strlen($source) > 4000000) {
                throw new RuntimeException('The reference image is unavailable.');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($source);
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw new RuntimeException('The image editor needs PNG or JPEG input.');
            }
            $payload = [
                'model' => $model, 'prompt' => $prompt,
                'image' => 'data:'.$mime.';base64,'.base64_encode($source),
                'n' => 1, 'response_format' => 'b64_json', 'seed' => $seed,
            ];
            if ($masked) {
                $mask = $task->cloud_mask_path ? Storage::disk('local')->get($task->cloud_mask_path) : null;
                if (! $mask || (new \finfo(FILEINFO_MIME_TYPE))->buffer($mask) !== 'image/png') {
                    throw new RuntimeException('A PNG mask is required.');
                }
                $payload['mask'] = 'data:image/png;base64,'.base64_encode($mask);
                $payload['mode'] = $mode;
            }
        }

        $response = Http::withToken((string) $key)->withHeaders($headers)
            ->connectTimeout(10)->timeout(135)->withOptions(['allow_redirects' => false])
            ->post($url, $payload);
        if (! $response->successful()) {
            throw new RuntimeException('Image model unavailable (HTTP '.$response->status().').');
        }
        if (strlen($response->body()) > 28000000) {
            throw new RuntimeException('Image response exceeded its limit.');
        }
        $result = $response->json();
        $encoded = $result['artifacts'][0]['base64'] ?? $result['data'][0]['b64_json'] ?? null;
        if (($result['artifacts'][0]['finishReason'] ?? 'SUCCESS') !== 'SUCCESS'
            || ! is_string($encoded) || strlen($encoded) > 27000000
            || ! preg_match('~^[a-zA-Z0-9+/]+={0,2}$~D', $encoded)) {
            throw new RuntimeException('Image model did not return a valid image.');
        }
        $binary = base64_decode($encoded, true);
        if (! $binary || strlen($binary) > 20000000) {
            throw new RuntimeException('Image output is too large or invalid.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        $size = @getimagesizefromstring($binary);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)
            || ! $size || $size[0] * $size[1] > 16000000) {
            throw new RuntimeException('Image output failed content validation.');
        }
        return ['binary' => $binary, 'mime' => $mime, 'model' => $model];
    }
}