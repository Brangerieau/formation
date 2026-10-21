<?php

namespace App\Controller;

use App\Dto\JsonRpcRequest;
use App\Mcp\McpTools;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP server over Streamable HTTP, JSON responses only (no SSE stream, no session).
 *
 * @see https://modelcontextprotocol.io/specification/2025-06-18/basic/transports
 */
class McpController extends AbstractController
{
    // Most recent first: used when the client asks for a version we don't know.
    private const array PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    #[Route('/mcp', name: 'mcp', methods: ['POST'], format: 'json')]
    public function handle(Request $request, #[MapRequestPayload] JsonRpcRequest $rpc, McpTools $tools): Response
    {
        // Required by the spec to block DNS rebinding: refuse browser calls from another origin.
        $origin = $request->headers->get('Origin');
        if (null !== $origin && parse_url($origin, \PHP_URL_HOST) !== $request->getHost()) {
            throw new AccessDeniedHttpException('Invalid Origin.');
        }

        if (null === $rpc->id) {
            return new Response(status: Response::HTTP_ACCEPTED);
        }

        try {
            $result = match ($rpc->method) {
                'initialize' => [
                    'protocolVersion' => \in_array($rpc->params['protocolVersion'] ?? null, self::PROTOCOL_VERSIONS, true) ? $rpc->params['protocolVersion'] : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'forum', 'version' => '1.0.0'],
                ],
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $tools->definitions()],
                'tools/call' => $tools->call($rpc->params['name'] ?? '', $rpc->params['arguments'] ?? []),
                default => throw new \BadMethodCallException(\sprintf('Method not found: %s', $rpc->method)),
            };
        } catch (\BadMethodCallException $e) {
            return $this->reply($rpc->id, error: ['code' => -32601, 'message' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return $this->reply($rpc->id, error: ['code' => -32602, 'message' => $e->getMessage()]);
        }

        return $this->reply($rpc->id, result: $result);
    }

    // JsonResponse rather than $this->json(): the Serializer would turn empty stdClass into [] instead of {}.
    private function reply(int|string $id, mixed $result = null, ?array $error = null): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id] + (null === $error ? ['result' => $result] : ['error' => $error]));
    }
}
