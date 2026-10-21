<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiCreditUsage;
use App\Services\FirstPartySignature;
use App\Services\HostedCodeAgents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class OpenMindAiGatewayController
{
    private function actor(Request $request, bool $firstParty): User
    {
        if ($firstParty) {
            return app(FirstPartySignature::class)->user($request);
        }
        $user = $request->user();
        abort_unless($user && $user->is_active, 401);
        $token = $user->currentAccessToken();
        abort_unless($token && ($token instanceof PersonalAccessToken) && $token->can('openmindai:invoke'), 403, 'An API key with model access is required.');
        return $user;
    }

    public function models(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, false);
        return ['object' => 'list', 'data' => $agents->publicModels()];
    }

    public function internalModels(Request $request, HostedCodeAgents $agents)
    {
        $this->actor($request, true);
        return ['object' => 'list', 'data' => $agents->publicModels()];
    }

    public function chat(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->completion($request, false, $agents, $credits);
    }

    public function internalChat(Request $request, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        return $this->completion($request, true, $agents, $credits);
    }

    private function completion(Request $request, bool $firstParty, HostedCodeAgents $agents, ApiCreditUsage $credits)
    {
        $user = $this->actor($request, $firstParty);
        $data = $request->validate([
            'model' => 'required|string|max:60',
            'messages' => 'required|array|min:1|max:40',
            'messages.*.role' => 'required|in:system,user,assistant',
            'messages.*.content' => 'required|string|max:16000',
            'stream' => 'sometimes|boolean',
            'max_tokens' => 'sometimes|integer|min:1|max:8192',
            'temperature' => 'sometimes|numeric|min:0|max:1',
        ]);
        $agent = $agents->find($data['model']);
        abort_unless($agent, 404, 'This model is not enabled.');
        $key = (string) config('models.nvidia_key');
        abort_unless(strlen($key) >= 12, 503, 'NVIDIA_API_KEY is not configured on the Laravel server.');
        $lock = Cache::lock('openmindai:gateway:'.$user->id, 240);
        abort_unless($lock->get(), 429, 'Another model request is already in progress.');
        $held = true;
        try {
            $connection = $credits->connection('nvidia', $agent->provider_model, 'code');
            $from = $credits->monthStart();
            $userUsed = (int) DB::table('api_credit_usage')->where('user_id', $user->id)->where('created_at', '>=', $from)->sum('credits');
            $modelUsed = (int) DB::table('api_credit_usage')->where('connection_id', $connection)->where('created_at', '>=', $from)->sum('credits');
            $userLimit = $user->monthly_credit_limit;
            $modelLimit = DB::table('api_model_connections')->where('id', $connection)->value('monthly_credit_limit');
            abort_if($userLimit !== null && $userUsed >= $userLimit, 429, 'Your monthly API allowance has been reached.');
            abort_if($modelLimit !== null && $modelUsed >= $modelLimit, 429, 'This model has reached its monthly allowance.');

            $stream = (bool) ($data['stream'] ?? false);
            $payload = [
                'model' => $agent->provider_model,
                'messages' => $data['messages'],
                'max_tokens' => $data['max_tokens'] ?? 2048,
                'stream' => $stream,
            ];
            if (isset($data['temperature'])) {
                $payload['temperature'] = $data['temperature'];
            }
            $upstream = Http::withToken($key)->accept($stream ? 'text/event-stream' : 'application/json')
                ->connectTimeout(10)->timeout(120)->withOptions(['allow_redirects' => false, 'stream' => $stream])
                ->post('https://integrate.api.nvidia.com/v1/chat/completions', $payload);
            if (! $upstream->successful()) {
                $code = $upstream->status();
                return response()->json(['error' => ['message' => $code === 429 ? 'Provider is rate limited.' : 'Model provider is temporarily unavailable.', 'type' => 'upstream_error']], $code === 429 ? 429 : 502);
            }

            $usageKey = 'gateway:'.bin2hex(random_bytes(16));
            if (! $stream) {
                $result = $upstream->json();
                abort_unless(is_array($result) && isset($result['choices'][0]['message']), 502, 'Invalid model response.');
                $credits->completed($user->id, $connection, $usageKey);
                return response()->json([
                    'id' => $result['id'] ?? $usageKey,
                    'object' => 'chat.completion',
                    'created' => $result['created'] ?? time(),
                    'model' => $agent->slug,
                    'choices' => $result['choices'],
                    'usage' => $result['usage'] ?? null,
                ])->header('Cache-Control', 'no-store');
            }

            $held = false;
            return response()->stream(function () use ($upstream, $credits, $user, $connection, $usageKey, $lock, $agent) {
                $body = $upstream->toPsrResponse()->getBody();
                $tail = '';
                $done = false;
                try {
                    while (! $body->eof()) {
                        if (connection_aborted()) {
                            break;
                        }
                        $chunk = $body->read(8192);
                        if ($chunk === '') {
                            break;
                        }
                        $tail = substr($tail.$chunk, -128);
                        if (str_contains($tail, 'data: [DONE]')) {
                            $done = true;
                        }
                        echo $chunk;
                        if (ob_get_level() > 0) {
                            @ob_flush();
                        }
                        flush();
                    }
                    if ($done) {
                        $credits->completed($user->id, $connection, $usageKey);
                    }
                } finally {
                    $body->close();
                    $lock->release();
                }
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
        } finally {
            if ($held) {
                $lock->release();
            }
        }
    }
}
