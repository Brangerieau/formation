<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class McpControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testHandshake(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])['result'];
        self::assertSame('2025-06-18', $result['protocolVersion']);
        self::assertArrayHasKey('tools', $result['capabilities']);

        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());

        self::assertSame([], $this->rpc('ping')['result']);
    }

    public function testListsEveryApiRouteAsTool(): void
    {
        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');

        self::assertEqualsCanonicalizing([
            'get_api_category_list',
            'post_api_category_create',
            'get_api_topic_list',
            'post_api_topic_create',
            'get_api_topic_show',
            'post_api_post_create',
        ], array_keys($tools));

        $createTopic = $tools['post_api_topic_create'];
        self::assertSame('Créer un sujet dans une catégorie', $createTopic['description']);
        self::assertEqualsCanonicalizing(['id', 'title', 'content', 'author'], $createTopic['inputSchema']['required']);
        self::assertSame(150, $createTopic['inputSchema']['properties']['title']['maxLength']);
        self::assertFalse($createTopic['annotations']['readOnlyHint']);
        self::assertTrue($tools['get_api_category_list']['annotations']['readOnlyHint']);
    }

    public function testCallsApiThroughTools(): void
    {
        $category = $this->callTool('post_api_category_create', ['name' => 'PHP']);
        self::assertFalse($category['isError']);
        $categoryId = json_decode($category['content'][0]['text'], true)['id'];

        $topic = $this->callTool('post_api_topic_create', ['id' => (string) $categoryId, 'title' => 'MCP', 'content' => 'JSON-RPC', 'author' => 'alice']);
        self::assertFalse($topic['isError']);
        $topicId = json_decode($topic['content'][0]['text'], true)['id'];

        $shown = $this->callTool('get_api_topic_show', ['id' => (string) $topicId]);
        self::assertSame('MCP', json_decode($shown['content'][0]['text'], true)['title']);
    }

    public function testApiErrorsAreToolErrors(): void
    {
        self::assertTrue($this->callTool('get_api_topic_show', ['id' => '999'])['isError']);
        self::assertTrue($this->callTool('post_api_category_create', [])['isError']);
    }

    public function testProtocolErrors(): void
    {
        self::assertSame(-32602, $this->rpc('tools/call', ['name' => 'nope'])['error']['code']);
        self::assertSame(-32601, $this->rpc('resources/list')['error']['code']);
    }

    public function testRejectsForeignOrigin(): void
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
    }

    private function callTool(string $name, array $arguments): array
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments])['result'];
    }

    private function rpc(string $method, array $params = []): array
    {
        $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $response['id']);

        return $response;
    }

    private function post(array $payload, array $server = []): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'] + $server, content: json_encode($payload));
    }
}
