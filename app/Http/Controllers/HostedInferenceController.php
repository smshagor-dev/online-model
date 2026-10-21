<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HostedInferenceController
{
    // Only server-to-server calls from the authenticated Next.js bridge may
    // invoke the fixed provider endpoints. No browser-selectable URL or key.
    public function invoke(Request $request)
    {
        $secret = (string) config('models.frontend_chat_key');
        abort_unless(strlen($secret) >= 32, 503, 'The inference bridge is not configured.');
        abort_unless(hash_equals($secret, (string) $request->header('X-Chat-Bridge-Key')), 403);

        $key = (string) config('models.nvidia_key');
        abort_unless(strlen($key) >= 12, 503, 'Hosted inference is not configured.');
        abort_if(strlen($request->getContent()) > 12000000, 413, 'The inference request is too large.');

        $data = $request->validate([
            'service' => 'required|in:chat,image,transcription,speech,video',
            'payload' => 'required|array',
        ]);
        $kind = $data['service'];
        $params = $data['payload'];

        $client = Http::withToken($key)->connectTimeout(10)
            ->timeout($kind === 'video' ? 190 : 120)
            ->withOptions(['allow_redirects' => false]);
        $stream = false;

        if ($kind === 'chat') {
            $model = $params['model'] ?? null;
            abort_unless(is_string($model) && strlen($model) <= 190, 422, 'Invalid chat model.');
            // The administrators' actual enabled catalog determines access.
            abort_unless(DB::table('hosted_code_agents')
                ->where('provider_model', $model)->where('enabled', true)
                ->where('api_ready', true)->where('api_protocol', 'chat')->exists(), 403, 'This chat model is not enabled.');
            $messages = $params['messages'] ?? null;
            abort_unless(is_array($messages) && count($messages) >= 1 && count($messages) <= 80
                && strlen(json_encode($messages) ?: '') <= 8000000, 422, 'Invalid chat context.');
            foreach ($messages as $message) {
                abort_unless(is_array($message)
                    && in_array($message['role'] ?? '', ['system', 'user', 'assistant', 'tool'], true)
                    && array_key_exists('content', $message), 422, 'Invalid chat message.');
            }
            $stream = ($params['stream'] ?? false) === true;
            $payload = [
                'model' => $model,
                'messages' => $messages,
                'stream' => $stream,
            ];
            if (isset($params['max_tokens'])) {
                abort_unless(is_int($params['max_tokens']) && $params['max_tokens'] >= 1
                    && $params['max_tokens'] <= 16384, 422, 'Invalid output limit.');
                $payload['max_tokens'] = $params['max_tokens'];
            }
            if (isset($params['temperature'])) {
                abort_unless(is_numeric($params['temperature']) &&
                    $params['temperature'] >= 0 && $params['temperature'] <= 1, 422, 'Invalid temperature.');
                $payload['temperature'] = (float) $params['temperature'];
            }
            if (isset($params['chat_template_kwargs']['enable_thinking'])) {
                abort_unless($params['chat_template_kwargs']['enable_thinking'] === false, 422);
                $payload['chat_template_kwargs'] = ['enable_thinking' => false];
            }
            $response = $client->accept($stream ? 'text/event-stream' : 'application/json')
                ->withOptions(['stream' => $stream])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', $payload);
        } elseif ($kind === 'image') {
            $prompt = $params['prompt'] ?? null;
            abort_unless(is_string($prompt) && trim($prompt) !== '' && strlen($prompt) <= 4000, 422, 'Invalid image prompt.');
            $response = $client->acceptJson()->post(
                'https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell',
                [
                    'prompt' => $prompt, 'mode' => 'base', 'cfg_scale' => 0,
                    'height' => 1024, 'width' => 1024, 'samples' => 1,
                    'seed' => 0, 'steps' => 4,
                ],
            );
        } elseif ($kind === 'transcription') {
            $encoded = $params['data'] ?? null;
            abort_unless(is_string($encoded) && strlen($encoded) <= 11000000, 422, 'Invalid recording.');
            $wav = base64_decode($encoded, true);
            abort_unless($wav !== false && strlen($wav) >= 44 && strlen($wav) <= 8000000
                && str_starts_with($wav, 'RIFF') && substr($wav, 8, 4) === 'WAVE', 422, 'Invalid WAV recording.');
            $response = $client->acceptJson()
                ->attach('file', $wav, 'recording.wav', ['Content-Type' => 'audio/wav'])
                ->post('https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions', [
                    'language' => 'en-US',
                ]);
        } elseif ($kind === 'speech') {
            $text = $params['text'] ?? null;
            $language = $params['language'] ?? 'en-US';
            $voices = [
                'en-US' => 'Magpie-Multilingual.EN-US.Aria',
                'es-US' => 'Magpie-Multilingual.ES-US.Diego',
                'fr-FR' => 'Magpie-Multilingual.FR-FR.Louise',
            ];
            abort_unless(is_string($text) && trim($text) !== '' && mb_strlen($text) <= 1000
                && is_string($language) && isset($voices[$language]), 422, 'Invalid speech request.');
            $response = $client->accept('audio/wav')->asMultipart()
                ->post('https://877104f7-e885-42b9-8de8-f6e4c6303969.invocation.api.nvcf.nvidia.com/v1/audio/synthesize', [
                    'text' => $text, 'language' => $language,
                    'voice' => $voices[$language],
                    'encoding' => 'LINEAR_PCM', 'sample_rate_hz' => '44100',
                ]);
        } else {
            $prompt = $params['prompt'] ?? null;
            abort_unless(is_string($prompt) && trim($prompt) !== '' && strlen($prompt) <= 4000, 422, 'Invalid video prompt.');
            $response = $client->acceptJson()->post('https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano', [
                'model_mode' => 'text2video', 'prompt' => $prompt,
                'resolution' => '480_16_9', 'num_frames' => 25,
                'num_inference_steps' => 35, 'fps' => 24, 'seed' => 42,
            ]);
        }

        if (! $response->successful()) {
            $code = $response->status();
            // Never expose third-party error bodies, URLs or credentials.
            return response()->json([
                'message' => $code === 429
                    ? 'The generation service is busy. Please retry.'
                    : ($code === 401 || $code === 403
                        ? 'This account cannot access the requested service.'
                        : 'The generation service is unavailable.'),
            ], $code === 429 ? 429 : 503)->header('Cache-Control', 'no-store');
        }

        if ($stream) {
            return response()->stream(function () use ($response) {
                $body = $response->toPsrResponse()->getBody();
                $buffer = '';
                $total = 0;
                try {
                    while (! $body->eof() && ! connection_aborted()) {
                        $chunk = $body->read(8192);
                        if ($chunk === '') {
                            break;
                        }
                        $buffer .= str_replace("\r\n", "\n", $chunk);
                        $total += strlen($chunk);
                        if (strlen($buffer) > 1048576 || $total > 24000000) {
                            break;
                        }
                        while (($end = strpos($buffer, "\n\n")) !== false) {
                            $frame = substr($buffer, 0, $end);
                            $buffer = substr($buffer, $end + 2);
                            foreach (explode("\n", $frame) as $line) {
                                if (! str_starts_with($line, 'data:')) {
                                    continue;
                                }
                                $json = trim(substr($line, 5));
                                if ($json === '[DONE]') {
                                    echo "data: [DONE]\n\n";

                                    return;
                                }
                                $event = json_decode($json, true);
                                if (is_array($event) && isset($event['choices'])) {
                                    unset($event['model']);
                                    echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)."\n\n";
                                }
                            }
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            flush();
                        }
                    }
                } finally {
                    $body->close();
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'private, no-store, no-transform',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $body = $response->body();
        if ($kind === 'speech') {
            abort_unless(strlen($body) >= 44 && strlen($body) <= 20000000 &&
                str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WAVE', 502, 'Invalid generated speech.');
            return response($body, 200, [
                'Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        abort_if(strlen($body) > ($kind === 'chat' ? 1000000 : 28000000), 502, 'The provider response is too large.');
        $json = json_decode($body, true);
        abort_unless(is_array($json), 502, 'The provider returned an invalid response.');

        return response()->json($json)->header('Cache-Control', 'private, no-store');
    }
}
