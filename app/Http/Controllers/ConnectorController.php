<?php

namespace App\Http\Controllers;

use App\Models\ConnectorAccount;
use App\Models\ConnectorCall;
use App\Services\McpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class ConnectorController
{
    private function server(string $server): array
    {
        $definition = config('connectors.servers.'.$server);
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $server) && is_array($definition) && ! empty($definition['url']), 404);

        return $definition;
    }

    private function exclusive(Request $r, \Closure $operation)
    {
        $lock = Cache::lock('connector-user:'.$r->user()->id, 300);
        abort_unless($lock->get(), 409, 'Another connector operation is running.');
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    public function index(Request $r)
    {
        return ['servers' => collect(config('connectors.servers'))->map(fn ($s, $id) => ['id' => $id, 'name' => $s['name'] ?? $id, 'connected' => ConnectorAccount::where('user_id', $r->user()->id)->where('server', $id)->exists()])->values(), 'calls' => ConnectorCall::where('user_id', $r->user()->id)->where('status', '!=', 'deleted')->latest('id')->limit(30)->get()];
    }

    public function connect(Request $r, string $server)
    {
        $definition = $this->server($server);
        $d = $r->validate(['credential' => 'nullable|string|max:4096', 'consent' => 'required|accepted']);
        abort_if(preg_match('/[\r\n]/', $d['credential'] ?? ''), 422);

        return $this->exclusive($r, function () use ($r, $server, $definition, $d) {
            $client = new McpClient($definition['url'], $d['credential'] ?? null);
            try {
                $client->initialize();
                $client->tools();
            } catch (Throwable) {
                abort(502, 'Could not connect. Check the MCP endpoint and credential.');
            } finally {
                $client->close();
            }
            ConnectorAccount::updateOrCreate(['user_id' => $r->user()->id, 'server' => $server], ['credential' => $d['credential'] ?? null, 'endpoint_hash' => hash('sha256', $definition['url'])]);

            return ['connected' => true];
        });
    }

    public function disconnect(Request $r, string $server)
    {
        // Serialize revocation with calls, so later operations cannot use a disconnected token.
        return $this->exclusive($r, function () use ($r, $server) {
            ConnectorAccount::where('user_id', $r->user()->id)->where('server', $server)->delete();

            return response()->noContent();
        });
    }

    private function client(Request $r, string $server): McpClient
    {
        $definition = $this->server($server);
        $account = ConnectorAccount::where('user_id', $r->user()->id)->where('server', $server)->firstOrFail();

        abort_unless(hash_equals($account->endpoint_hash, hash('sha256', $definition['url'])), 409, 'Connector endpoint changed. Review and reconnect before using it.');

        return new McpClient($definition['url'], $account->credential);
    }

    public function tools(Request $r, string $server)
    {
        $d = $r->validate(['cursor' => 'nullable|string|max:2000']);
        $client = $this->client($r, $server);
        try {
            $client->initialize();

            return $client->tools($d['cursor'] ?? null);
        } catch (Throwable) {
            abort(502, 'Could not load MCP tools.');
        } finally {
            $client->close();
        }
    }

    public function call(Request $r, string $server)
    {
        $d = $r->validate(['tool' => 'required|string|max:120', 'arguments' => 'present|array', 'approved' => 'required|accepted', 'request_key' => 'required|uuid']);
        abort_if(strlen(json_encode($d['arguments'])) > 32000, 422, 'Arguments exceed limit.');

        return $this->exclusive($r, function () use ($r, $server, $d) {
            $client = $this->client($r, $server);
            $prior = ConnectorCall::where('user_id', $r->user()->id)->where('request_key', $d['request_key'])->first();
            if ($prior) {
                abort_unless($prior->fingerprint === hash('sha256', json_encode([$server, $d['tool'], $d['arguments']])), 409, 'Request key belongs to different arguments.');

                return $prior;
            }
            $quota = 'connector-day:'.$r->user()->id;
            abort_if(RateLimiter::tooManyAttempts($quota, max(1, config('connectors.daily_limit'))), 429, 'Daily connector call limit reached.');
            abort_if(ConnectorCall::where('user_id', $r->user()->id)->where('status', '!=', 'deleted')->count() >= 100, 422, 'Clear older saved tool results first.');
            RateLimiter::hit($quota, 86400);
            $run = ConnectorCall::create(['user_id' => $r->user()->id, 'server' => $server, 'tool' => $d['tool'], 'arguments' => $d['arguments'], 'request_key' => $d['request_key'], 'fingerprint' => hash('sha256', json_encode([$server, $d['tool'], $d['arguments']])), 'status' => 'running']);
            try {
                $client->initialize();
                // Discovery is paginated; call uses the exact reviewed tool name/arguments.
                $result = $client->call($d['tool'], $d['arguments']);
                $run->update(['status' => ! empty($result['isError']) ? 'failed' : 'complete', 'result' => $result]);
            } catch (Throwable) {
                $run->update(['status' => 'unknown', 'result' => ['content' => [['type' => 'text', 'text' => 'The server did not confirm completion. Check the external service before retrying; an action may have happened.']]]]);
            } finally {
                $client->close();
            }

            return $run->fresh();
        });
    }

    public function deleteCall(Request $r, int $call)
    {
        return $this->exclusive($r, function () use ($r, $call) {
            ConnectorCall::where('user_id', $r->user()->id)->findOrFail($call)->update(['arguments' => [], 'result' => null, 'status' => 'deleted']);

            return response()->noContent();
        });
    }
}
