<?php

namespace App\Mcp;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal MCP client for our own /mcp server: JSON-RPC over HTTP, JSON responses only.
 *
 * ponytail: no initialize handshake, session id or SSE; our server doesn't need them. A third-party MCP server will.
 */
class McpClient
{
    public function __construct(
        private HttpClientInterface $mcpClient,
    ) {
    }

    /**
     * @return list<array> MCP tool definitions
     */
    public function tools(): array
    {
        $response = $this->rpc('tools/list');
        if (isset($response['error'])) {
            throw new \RuntimeException(\sprintf('MCP tools/list failed: %s', $response['error']['message']));
        }

        return $response['result']['tools'];
    }

    /**
     * @return string the tool output, prefixed with "Erreur" when it failed so the model can tell
     */
    public function call(string $name, array $arguments): string
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        if (isset($response['error'])) {
            return 'Erreur : '.$response['error']['message'];
        }

        $text = implode("\n", array_column($response['result']['content'], 'text'));

        return ($response['result']['isError'] ?? false) ? 'Erreur : '.$text : $text;
    }

    private function rpc(string $method, array $params = []): array
    {
        return $this->mcpClient->request('POST', '/mcp', ['json' => [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ]])->toArray();
    }
}
