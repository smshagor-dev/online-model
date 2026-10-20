<?php

namespace Tests\Feature;

use App\Models\ConnectorAccount;
use App\Models\ConnectorCall;
use App\Models\User;
use App\Services\McpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectorTest extends TestCase
{
    use RefreshDatabase;

    private int $executions = 0;

    private function setupConnector(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::create(['name' => 'User', 'email' => uniqid().'@example.com', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($user);
        config(['connectors.servers' => ['docs' => ['name' => 'Private docs', 'url' => 'https://mcp.example.com/mcp']]]);
        Http::fake(function ($r) {
            if ($r->method() === 'DELETE') {
                return Http::response('', 204);
            }
            $body = $r->data();
            $method = $body['method'];
            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }
            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []]],
                'tools/list' => ['tools' => [['name' => 'lookup', 'inputSchema' => ['type' => 'object']]], 'nextCursor' => 'next-page'],
                'tools/call' => $this->execute(),
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result], 200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'private-session']);
        });
        $this->postJson('/api/v1/connectors/docs/connect', ['credential' => 'secret-token', 'consent' => true])->assertOk();
    }

    private function execute(): array
    {
        $this->executions++;

        return ['content' => [['type' => 'text', 'text' => 'Result contains secret-token safely redacted']]];
    }

    public function test_connect_discover_call_approval_idempotency_and_revocation(): void
    {
        $this->setupConnector();
        $account = ConnectorAccount::first();
        $this->assertStringNotContainsString('secret-token', $account->getRawOriginal('credential'));
        $this->getJson('/api/v1/connectors')->assertDontSee('secret-token')->assertDontSee('private-session');
        $this->getJson('/api/v1/export')->assertOk()->assertDontSee('secret-token')->assertDontSee('endpoint_hash');
        $this->postJson('/api/v1/connectors/docs/tools', ['cursor' => 'next-page'])->assertOk()->assertJsonPath('nextCursor', 'next-page');
        Http::assertSent(fn ($r) => ($r['method'] ?? '') === 'tools/list' && ($r['params']->cursor ?? '') === 'next-page' && $r->hasHeader('Mcp-Session-Id', 'private-session') && $r->hasHeader('Authorization', 'Bearer secret-token'));
        $d = ['tool' => 'lookup', 'arguments' => ['query' => 'hello'], 'request_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/connectors/docs/call', $d)->assertUnprocessable();
        $call = $this->postJson('/api/v1/connectors/docs/call', $d + ['approved' => true])->assertOk()->assertJsonPath('status', 'complete')->assertDontSee('secret-token')->json('id');
        $this->postJson('/api/v1/connectors/docs/call', $d + ['approved' => true])->assertOk();
        $this->assertSame(1, $this->executions);
        $this->postJson('/api/v1/connectors/docs/call', array_replace($d, ['arguments' => ['query' => 'changed'], 'approved' => true]))->assertStatus(409);
        $this->assertStringNotContainsString('hello', ConnectorCall::first()->getRawOriginal('arguments'));
        $this->deleteJson("/api/v1/connector-calls/$call")->assertNoContent();
        $this->postJson('/api/v1/connectors/docs/call', $d + ['approved' => true])->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertSame(1, $this->executions);
        $this->deleteJson('/api/v1/connectors/docs')->assertNoContent();
        $this->postJson('/api/v1/connectors/docs/tools')->assertNotFound();
    }

    public function test_unknown_operation_is_not_retried_and_user_cannot_supply_endpoint(): void
    {
        $this->setupConnector();
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response('', 503));
        $d = ['tool' => 'write', 'arguments' => [], 'request_key' => (string) Str::uuid(), 'approved' => true];
        $this->postJson('/api/v1/connectors/docs/call', $d)->assertOk()->assertJsonPath('status', 'unknown');
        $before = Http::recorded()->count();
        $this->postJson('/api/v1/connectors/docs/call', $d)->assertOk();
        $this->assertSame($before, Http::recorded()->count());
        $this->postJson('/api/v1/connectors/arbitrary/connect', ['url' => 'http://169.254.169.254', 'consent' => true])->assertNotFound();
        $this->deleteJson('/api/v1/connector-calls/999')->assertNotFound();
    }

    public function test_sse_results_are_correlated_and_server_requests_are_rejected(): void
    {
        Http::fake(fn ($r) => Http::response("event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $r['id'], 'result' => ['tools' => []]])."\n\n", 200, ['Content-Type' => 'text/event-stream']));
        $client = new McpClient('https://mcp.example.com/mcp');
        $this->assertSame([], $client->tools()['tools']);
        Http::swap(new Factory);
        Http::fake(fn ($r) => Http::response("data: {\"jsonrpc\":\"2.0\",\"id\":123,\"method\":\"sampling/createMessage\"}\n\n", 200, ['Content-Type' => 'text/event-stream']));
        $this->expectException(\RuntimeException::class);
        $client->tools();
    }

    public function test_changed_endpoint_requires_new_consent_and_other_users_cannot_access_credentials_or_results(): void
    {
        $this->setupConnector();
        config(['connectors.servers.docs.url' => 'https://changed.example.com/mcp']);
        $this->postJson('/api/v1/connectors/docs/tools')->assertStatus(409);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'changed.example.com'));
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'StrongPassword123']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/connectors/docs/tools')->assertNotFound();
        $this->getJson('/api/v1/connectors')->assertJsonCount(0, 'calls')->assertJsonPath('servers.0.connected', false);
    }

    public function test_daily_connector_limit_counts_new_operations_only(): void
    {
        $this->setupConnector();
        config(['connectors.daily_limit' => 1]);
        $d = ['tool' => 'lookup', 'arguments' => [], 'request_key' => (string) Str::uuid(), 'approved' => true];
        $this->postJson('/api/v1/connectors/docs/call', $d)->assertOk();
        $this->postJson('/api/v1/connectors/docs/call', $d)->assertOk();
        $this->postJson('/api/v1/connectors/docs/call', array_replace($d, ['request_key' => (string) Str::uuid()]))->assertStatus(429);
        $this->assertSame(1, $this->executions);
    }
}
