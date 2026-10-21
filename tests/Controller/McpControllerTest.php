<?php

namespace App\Tests\Controller;

use App\Mcp\ApiToolRegistry;
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

    public function testListsApiRoutesWithDeletesByName(): void
    {
        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');

        self::assertEqualsCanonicalizing([
            'get_api_category_list',
            'post_api_category_create',
            'get_api_topic_list',
            'post_api_topic_create',
            'get_api_topic_show',
            'post_api_post_create',
            'get_api_doc_search',
            'delete_category',
            'delete_topic',
            'delete_post',
        ], array_keys($tools));
        self::assertSame(['name'], $tools['delete_category']['inputSchema']['required']);
        self::assertTrue($tools['delete_post']['annotations']['destructiveHint']);

        $createTopic = $tools['post_api_topic_create'];
        self::assertSame('Créer un sujet dans une catégorie', $createTopic['description']);
        self::assertEqualsCanonicalizing(['id', 'title', 'content', 'author'], $createTopic['inputSchema']['required']);
        self::assertSame(150, $createTopic['inputSchema']['properties']['title']['maxLength']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false], $createTopic['annotations']);
        self::assertTrue($tools['get_api_category_list']['annotations']['readOnlyHint']);

        // The docs search is read-only: the chatbot runs it without asking.
        // No limit for the model to raise: each section it reads costs seconds on CPU.
        self::assertSame(['q'], array_keys((array) $tools['get_api_doc_search']['inputSchema']['properties']));
        self::assertSame(['q'], $tools['get_api_doc_search']['inputSchema']['required']);
        self::assertSame(['readOnlyHint' => true, 'destructiveHint' => false], $tools['get_api_doc_search']['annotations']);
    }

    public function testOperationsAreDestructiveOnlyWhenTheyDeclareIt(): void
    {
        // The API deletes are hidden from tools/list, but still declared in the OpenAPI spec.
        $annotations = array_column(static::getContainer()->get(ApiToolRegistry::class)->definitions(), 'annotations', 'name');

        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true], $annotations['delete_api_category_delete']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false], $annotations['post_api_category_create']);
        self::assertSame(['readOnlyHint' => true, 'destructiveHint' => false], $annotations['get_api_category_list']);
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

        $deleted = $this->callTool('delete_topic', ['title' => 'mcp']);
        self::assertSame(['content' => [['type' => 'text', 'text' => "Sujet « MCP » (id $topicId) supprimé, avec ses messages."]], 'isError' => false], $deleted);
        self::assertTrue($this->callTool('get_api_topic_show', ['id' => (string) $topicId])['isError']);
    }

    public function testDeletesByNameAfterLookingUpTheIdThroughTheApi(): void
    {
        $php = $this->createThroughTool('post_api_category_create', ['name' => 'PHP']);
        $this->createThroughTool('post_api_category_create', ['name' => 'PHP avancé']);
        $symfony8 = $this->createThroughTool('post_api_topic_create', ['id' => (string) $php, 'title' => 'Symfony 8', 'content' => 'c', 'author' => 'alice']);
        $this->createThroughTool('post_api_topic_create', ['id' => (string) $php, 'title' => 'Symfony 7', 'content' => 'c', 'author' => 'alice']);
        foreach ([['alice', 'Bonjour à tous'], ['bob', 'Bonjour'], ['bob', 'Merci pour la réponse']] as [$author, $content]) {
            $this->createThroughTool('post_api_post_create', ['id' => (string) $symfony8, 'content' => $content, 'author' => $author]);
        }

        // Messages: author and/or excerpt, exactly one match.
        self::assertStringStartsWith('Indique', $this->toolError('delete_post', ['topic' => 'Symfony 8']));
        self::assertStringStartsWith('Plusieurs messages', $this->toolError('delete_post', ['topic' => 'Symfony 8', 'author' => 'bob']));
        self::assertFalse($this->callTool('delete_post', ['topic' => 'symfony 8', 'author' => 'BOB', 'excerpt' => 'merci'])['isError']);
        self::assertSame(['Bonjour à tous', 'Bonjour'], array_column($this->apiResult('get_api_topic_show', ['id' => (string) $symfony8])['posts'], 'content'));

        // Topics: searched in every category; no exact title and several partial ones is ambiguous.
        $ambiguous = $this->toolError('delete_topic', ['title' => 'Symfony']);
        self::assertStringStartsWith('Plusieurs sujets correspondent à « Symfony », précise lequel : ', $ambiguous);
        self::assertStringContainsString('« Symfony 7 » (catégorie PHP)', $ambiguous);
        self::assertStringContainsString('« Symfony 8 » (catégorie PHP)', $ambiguous);
        self::assertStringStartsWith('Aucun(e) sujet', $this->toolError('delete_topic', ['title' => 'Inconnu']));
        self::assertFalse($this->callTool('delete_topic', ['title' => 'Symfony 7'])['isError']);

        // Categories: the exact name wins over a partial match.
        self::assertFalse($this->callTool('delete_category', ['name' => 'php'])['isError']);
        self::assertSame(['PHP avancé'], array_column($this->apiResult('get_api_category_list'), 'name'));

        // The generated deletes by id are not tools anymore.
        self::assertSame(-32602, $this->rpc('tools/call', ['name' => 'delete_api_category_delete', 'arguments' => ['id' => '1']])['error']['code']);
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

    private function createThroughTool(string $name, array $arguments): int
    {
        return $this->apiResult($name, $arguments)['id'];
    }

    private function apiResult(string $name, array $arguments = []): array
    {
        $result = $this->callTool($name, $arguments);
        self::assertFalse($result['isError']);

        return json_decode($result['content'][0]['text'], true);
    }

    private function toolError(string $name, array $arguments): string
    {
        $result = $this->callTool($name, $arguments);
        self::assertTrue($result['isError']);

        return $result['content'][0]['text'];
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
