<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Restricted Streamable HTTP client for MCP 2025-11-25 / 2025-06-18.
// No stdio commands, server sampling, automatic actions, redirects or arbitrary user URLs.
class McpClient
{
    private ?string $session = null;

    private string $version = '2025-11-25';

    private int $sequence = 0;

    private bool $negotiated = false;

    public function __construct(private string $url, private ?string $credential = null)
    {
        $p = parse_url($url);
        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query'])) {
            throw new RuntimeException('MCP endpoints must use HTTPS without embedded credentials or query strings.');
        }
    }

    public function initialize(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => $this->version, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Openmindai', 'version' => '1.0']]);
        if (! in_array($result['protocolVersion'] ?? '', ['2025-11-25', '2025-06-18'], true) || ! array_key_exists('tools', $result['capabilities'] ?? [])) {
            throw new RuntimeException('Unsupported MCP server capabilities/version.');
        }
        $this->version = $result['protocolVersion'];
        $this->negotiated = true;
        $this->rpc('notifications/initialized', [], true);
    }

    public function tools(?string $cursor = null): array
    {
        $result = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : []);
        if (! is_array($result['tools'] ?? null) || count($result['tools']) > 200) {
            throw new RuntimeException('Invalid MCP tool catalogue.');
        }

        return $result;
    }

    public function call(string $name, array $arguments): array
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
    }

    public function close(): void
    {
        if ($this->session) {
            try {
                $this->request()->delete($this->url);
            } catch (\Throwable) { /* Closing cannot repeat an action. */
            }
            $this->session = null;
        }
    }

    private function request()
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($this->negotiated) {
            $headers['MCP-Protocol-Version'] = $this->version;
        }
        if ($this->session) {
            $headers['Mcp-Session-Id'] = $this->session;
        }
        if ($this->credential) {
            $headers['Authorization'] = 'Bearer '.$this->credential;
        }

        return Http::withHeaders($headers)->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 20, 'progress' => function ($total, $downloaded) {
            if ($downloaded > 1000000) {
                throw new RuntimeException('MCP response exceeds limit.');
            }
        }]);
    }

    private function rpc(string $method, array $params, bool $notification = false): array
    {
        $id = ++$this->sequence;
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if (! $notification) {
            $body['id'] = $id;
        }
        $response = $this->request()->post($this->url, $body);
        if ($notification && $response->status() === 202) {
            return [];
        }
        if (! $response->successful()) {
            throw new RuntimeException('MCP request failed.');
        }
        if ($method === 'initialize' && $response->header('Mcp-Session-Id')) {
            $session = $response->header('Mcp-Session-Id');
            if (! preg_match('/^[\x21-\x7E]{1,512}$/', $session)) {
                throw new RuntimeException('Invalid MCP session.');
            }
            $this->session = $session;
        }
        $stream = $response->toPsrResponse()->getBody();
        $raw = '';
        $bytes = 0;
        $deadline = microtime(true) + 20;
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);
                $raw .= $chunk;
                $bytes += strlen($chunk);
                if (microtime(true) > $deadline || $bytes > 1000000) {
                    throw new RuntimeException('MCP response exceeds limit.');
                }
                if (str_contains(strtolower($response->header('Content-Type')), 'text/event-stream')) {
                    while (($end = strpos(str_replace("\r\n", "\n", $raw), "\n\n")) !== false) {
                        $raw = str_replace("\r\n", "\n", $raw);
                        $event = substr($raw, 0, $end);
                        $raw = substr($raw, $end + 2);
                        $parts = [];
                        foreach (explode("\n", $event) as $line) {
                            if (str_starts_with($line, 'data:')) {
                                $parts[] = ltrim(substr($line, 5), ' ');
                            }
                        }
                        if (! $parts || ! trim(implode("\n", $parts))) {
                            continue;
                        }
                        $data = json_decode(implode("\n", $parts), true);
                        if (isset($data['method']) && isset($data['id'])) {
                            throw new RuntimeException('Server-initiated requests are unsupported.');
                        }
                        if (($data['id'] ?? null) === $id) {
                            return $this->result($data);
                        }
                    }
                }
            }
        } finally {
            $stream->close();
        }
        $data = json_decode($raw, true);
        if (! is_array($data) || ($data['id'] ?? null) !== $id) {
            throw new RuntimeException('Invalid MCP response.');
        }

        return $this->result($data);
    }

    private function result(array $data): array
    {
        if (($data['jsonrpc'] ?? '') !== '2.0' || isset($data['error']) || ! is_array($data['result'] ?? null)) {
            throw new RuntimeException('MCP returned an error or invalid response.');
        }

        // Never echo an exact bearer credential if a server returns it in a result.
        return $this->credential ? $this->redact($data['result']) : $data['result'];
    }

    private function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace($this->credential, '[redacted]', $value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $clean[is_string($key) ? str_replace($this->credential, '[redacted]', $key) : $key] = $this->redact($item);
        }

        return $clean;
    }
}
