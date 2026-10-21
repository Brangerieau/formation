<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class ChatControllerTest extends WebTestCase
{
    /** @var list<array{name: string, arguments: array}> tools/call requests received by the mocked MCP server */
    private array $mcpCalls = [];

    public function testConversationIsSentToTheLlmUntilReset(): void
    {
        $llmResponses = [$this->llmReply('Salut !'), $this->llmReply('Rien de neuf.'), $this->llmReply('Re !')];
        $client = $this->createChatClient($llmResponses);
        $systemPrompt = static::getContainer()->getParameter('app.chat.system_prompt');

        $this->chat($client, 'Bonjour');
        self::assertResponseIsSuccessful();
        self::assertSame(['reply' => 'Salut !'], json_decode($client->getResponse()->getContent(), true));
        self::assertStringEndsWith('/v1/chat/completions', $llmResponses[0]->getRequestUrl());
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
        ], $this->sentMessages($llmResponses[0]));

        $this->chat($client, 'Quoi de neuf ?');
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Salut !'],
            ['role' => 'user', 'content' => 'Quoi de neuf ?'],
        ], $this->sentMessages($llmResponses[1]));

        $client->request('GET', '/');
        self::assertSelectorCount(4, '#chat .chat-msg');
        self::assertSelectorTextSame('#chat .chat-msg-assistant:last-child', 'Rien de neuf.');

        $client->request('POST', '/chat/reset');
        self::assertResponseStatusCodeSame(204);

        $this->chat($client, 'Re');
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Re'],
        ], $this->sentMessages($llmResponses[2]));
    }

    public function testFailedLlmCallIsNotKeptInHistory(): void
    {
        $llmResponses = [new JsonMockResponse([], ['http_code' => 500]), $this->llmReply('Salut !')];
        $client = $this->createChatClient($llmResponses);

        $this->chat($client, 'Perdu');
        self::assertResponseStatusCodeSame(500);

        $this->chat($client, 'Bonjour');
        self::assertSame(['system', 'user'], array_column($this->sentMessages($llmResponses[1]), 'role'));
    }

    public function testToolResultIsSentBackThenTheModelHasToAnswer(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['get_api_category_list', '{}']]),
            $this->llmReply('Il y a une catégorie : Général.'),
            $this->llmReply('De rien.'),
        ];
        $client = $this->createChatClient($llmResponses);

        $this->chat($client, 'Liste les catégories');
        self::assertSame(['reply' => 'Il y a une catégorie : Général.'], json_decode($client->getResponse()->getContent(), true));

        // MCP tools are offered in the OpenAI format, a parameterless one keeping "properties": {}.
        $firstRequest = $llmResponses[0]->getRequestOptions()['body'];
        self::assertSame([['type' => 'function', 'function' => [
            'name' => 'get_api_category_list',
            'description' => 'Lister toutes les catégories du forum',
            'parameters' => ['properties' => [], 'type' => 'object', 'required' => []],
        ]]], json_decode($firstRequest, true)['tools']);
        self::assertStringContainsString('"properties":{}', $firstRequest);
        self::assertSame([['name' => 'get_api_category_list', 'arguments' => []]], $this->mcpCalls);

        $secondRequest = json_decode($llmResponses[1]->getRequestOptions()['body'], true);
        self::assertArrayNotHasKey('tools', $secondRequest);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Résultat de get_api_category_list'], $secondRequest['messages'][3]);

        // Tool calls stay in the history sent to the LLM, but not in the widget.
        $this->chat($client, 'Merci');
        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($this->sentMessages($llmResponses[2]), 'role'));
        $client->request('GET', '/');
        self::assertSelectorCount(4, '#chat .chat-msg');
    }

    public function testOnlyTheFirstToolCallRuns(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['post_api_category_create', '{"name":"A"}'], 'call_2' => ['post_api_category_create', '{"name":"B"}']]),
            $this->llmReply('J\'ai créé A, voulez-vous que je crée B ?'),
        ];
        $client = $this->createChatClient($llmResponses);

        $this->chat($client, 'Crée les catégories A et B');

        self::assertSame([['name' => 'post_api_category_create', 'arguments' => ['name' => 'A']]], $this->mcpCalls);
        [, , , $first, $second] = $this->sentMessages($llmResponses[1]);
        self::assertSame('Résultat de post_api_category_create', $first['content']);
        self::assertSame('call_2', $second['tool_call_id']);
        self::assertStringStartsWith('Non exécuté', $second['content']);
    }

    public function testInvalidToolArgumentsAreReportedToTheModel(): void
    {
        $llmResponses = [$this->llmToolCalls(['call_1' => ['post_api_category_create', '{oops']]), $this->llmReply('Désolé.')];
        $client = $this->createChatClient($llmResponses);

        $this->chat($client, 'Crée une catégorie');

        self::assertSame([], $this->mcpCalls);
        self::assertSame('Erreur : arguments JSON invalides.', $this->sentMessages($llmResponses[1])[3]['content']);
    }

    public function testInvalidMessageReturns422(): void
    {
        $client = static::createClient();
        foreach ([['message' => ''], [], ['message' => str_repeat('a', 2001)]] as $payload) {
            $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
            self::assertResponseStatusCodeSame(422);
        }
    }

    /**
     * @param list<JsonMockResponse> $llmResponses
     */
    private function createChatClient(array $llmResponses): KernelBrowser
    {
        $client = static::createClient();
        // The mocks are set on this kernel: a reboot between requests would bring the real clients back.
        $client->disableReboot();
        static::getContainer()->set('ollama.client', new MockHttpClient($llmResponses));
        static::getContainer()->set('mcp.client', new MockHttpClient(function (string $method, string $url, array $options) {
            $rpc = json_decode($options['body'], true);
            if ('tools/call' === $rpc['method']) {
                $this->mcpCalls[] = $rpc['params'];

                return new JsonMockResponse(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => ['content' => [['type' => 'text', 'text' => 'Résultat de '.$rpc['params']['name']]], 'isError' => false]]);
            }

            return new JsonMockResponse(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => ['tools' => [[
                'name' => 'get_api_category_list',
                'description' => 'Lister toutes les catégories du forum',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ]]]]);
        }));

        return $client;
    }

    private function chat(KernelBrowser $client, string $message): void
    {
        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => $message]));
    }

    private function llmReply(string $content): JsonMockResponse
    {
        return new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]);
    }

    /**
     * @param array<string, array{string, string}> $calls tool call id => [tool name, JSON arguments]
     */
    private function llmToolCalls(array $calls): JsonMockResponse
    {
        $toolCalls = [];
        foreach ($calls as $id => [$name, $arguments]) {
            $toolCalls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]];
        }

        return new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => $toolCalls]]]]);
    }

    private function sentMessages(JsonMockResponse $response): array
    {
        return json_decode($response->getRequestOptions()['body'], true)['messages'];
    }
}
