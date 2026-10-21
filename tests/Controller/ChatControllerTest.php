<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

class ChatControllerTest extends WebTestCase
{
    /** @var list<array{name: string, arguments: array}> tools/call requests received by the mocked MCP server */
    private array $mcpCalls = [];

    /** @var list<string> X-Trace-Id header of each request to the mocked MCP server */
    private array $mcpTraceIds = [];

    public function testConversationIsSentToTheLlmUntilReset(): void
    {
        $llmResponses = [$this->llmReply('Sal', "ut\n", ' !'), $this->llmReply('Rien de neuf.'), $this->llmReply('Re !')];
        $client = $this->createChatClient($llmResponses);
        $systemPrompt = static::getContainer()->getParameter('app.chat.system_prompt');

        // The answer is passed on piece by piece, as the LLM writes it, a multi-line piece included.
        self::assertSame([['delta', 'Sal'], ['delta', "ut\n"], ['delta', ' !'], ['done', '']], $this->chat($client, 'Bonjour'));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', $client->getResponse()->headers->get('content-type'));
        self::assertStringEndsWith('/v1/chat/completions', $llmResponses[0]->getRequestUrl());
        self::assertTrue(json_decode($llmResponses[0]->getRequestOptions()['body'], true)['stream']);
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
        ], $this->sentMessages($llmResponses[0]));

        $this->chat($client, 'Quoi de neuf ?');
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => "Salut\n !"],
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

        // The status is sent before the LLM is called: the failure comes as an event.
        self::assertSame([['error', '']], $this->chat($client, 'Perdu'));

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

        $events = $this->chat($client, 'Liste les catégories');
        // The tool call is shown before the answer, with its readable label and result.
        self::assertSame(['tool', 'delta', 'done'], array_column($events, 0));
        self::assertSame('Il y a une catégorie : Général.', $events[1][1]);
        $tool = new Crawler($events[0][1]);
        self::assertSame('chat-tool chat-tool-ok', $tool->filter('details')->attr('class'));
        self::assertSame('Lister toutes les catégories du forum ok', $tool->filter('summary')->text());
        self::assertSame('Résultat de get_api_category_list', $tool->filter('pre')->text());

        // MCP tools are offered in the OpenAI format, a parameterless one keeping "properties": {}.
        $firstRequest = $llmResponses[0]->getRequestOptions()['body'];
        self::assertSame([['type' => 'function', 'function' => [
            'name' => 'get_api_category_list',
            'description' => 'Lister toutes les catégories du forum',
            'parameters' => ['properties' => [], 'type' => 'object', 'required' => []],
        ]]], json_decode($firstRequest, true)['tools']);
        self::assertStringContainsString('"properties":{}', $firstRequest);
        self::assertSame([['name' => 'get_api_category_list', 'arguments' => []]], $this->mcpCalls);
        // tools/list and tools/call carry the trace id of this /chat request, so both sides log under it.
        self::assertCount(2, $this->mcpTraceIds);
        self::assertMatchesRegularExpression('/^X-Trace-Id: [0-9a-f]{8}$/', $this->mcpTraceIds[0]);
        self::assertSame($this->mcpTraceIds[0], $this->mcpTraceIds[1]);

        $secondRequest = json_decode($llmResponses[1]->getRequestOptions()['body'], true);
        self::assertArrayNotHasKey('tools', $secondRequest);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Résultat de get_api_category_list'], $secondRequest['messages'][3]);

        // Tool calls stay in the history sent to the LLM, but not in the widget.
        $this->chat($client, 'Merci');
        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($this->sentMessages($llmResponses[2]), 'role'));
        $client->request('GET', '/');
        self::assertSelectorCount(4, '#chat .chat-msg');
        self::assertSelectorTextSame('#chat .chat-tool summary', 'Lister toutes les catégories du forum ok');
    }

    public function testOnlyTheFirstToolCallRuns(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['post_api_category_create', '{"name":"A"}'], 'call_2' => ['post_api_category_create', '{"name":"B"}']]),
            $this->llmReply('J\'ai créé A, voulez-vous que je crée B ?'),
        ];
        $client = $this->createChatClient($llmResponses);

        $tools = new Crawler(implode('', array_column(\array_slice($this->chat($client, 'Crée les catégories A et B'), 0, 2), 1)));

        self::assertSame([['name' => 'post_api_category_create', 'arguments' => ['name' => 'A']]], $this->mcpCalls);
        self::assertSame('post_api_category_create (name: A) ok', $tools->filter('.chat-tool-ok summary')->text());
        self::assertSame('post_api_category_create (name: B) non exécuté', $tools->filter('.chat-tool-skipped summary')->text());
        [, , , $first, $second] = $this->sentMessages($llmResponses[1]);
        self::assertSame('Résultat de post_api_category_create', $first['content']);
        self::assertSame('call_2', $second['tool_call_id']);
        self::assertStringStartsWith('Non exécuté', $second['content']);
    }

    public function testInvalidToolArgumentsAreReportedToTheModel(): void
    {
        $llmResponses = [$this->llmToolCalls(['call_1' => ['post_api_category_create', '{oops']]), $this->llmReply('Désolé.')];
        $client = $this->createChatClient($llmResponses);

        $tool = new Crawler($this->chat($client, 'Crée une catégorie')[0][1]);

        self::assertSame([], $this->mcpCalls);
        self::assertSame('Erreur : arguments JSON invalides.', $tool->filter('.chat-tool-error pre')->text());
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
     * @param list<MockResponse> $llmResponses
     */
    private function createChatClient(array $llmResponses): KernelBrowser
    {
        $client = static::createClient();
        // The mocks are set on this kernel: a reboot between requests would bring the real clients back.
        $client->disableReboot();
        static::getContainer()->set('ollama.client', new MockHttpClient($llmResponses));
        static::getContainer()->set('mcp.client', new MockHttpClient(function (string $method, string $url, array $options) {
            $rpc = json_decode($options['body'], true);
            $this->mcpTraceIds[] = $options['normalized_headers']['x-trace-id'][0] ?? '';
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

    /**
     * @return list<array{string, string}> the server-sent events, as [type, data]
     */
    private function chat(KernelBrowser $client, string $message): array
    {
        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => $message]));

        $events = [];
        foreach (explode("\n\n", trim($client->getInternalResponse()->getContent(), "\n")) as $block) {
            preg_match('/^event: (.*)$/m', $block, $type);
            preg_match_all('/^data: (.*)$/m', $block, $data);
            $events[] = [$type[1], implode("\n", $data[1])];
        }

        return $events;
    }

    /**
     * @param list<array> $deltas
     */
    private function llmStream(array $deltas): MockResponse
    {
        $body = array_map(fn (array $delta) => 'data: '.json_encode(['choices' => [['index' => 0, 'delta' => $delta]]])."\n\n", $deltas);

        // Split mid-line: an event can arrive across network chunks.
        return new MockResponse([...str_split(implode('', $body), 50), "data: [DONE]\n\n"], ['response_headers' => ['content-type' => 'text/event-stream']]);
    }

    private function llmReply(string ...$pieces): MockResponse
    {
        return $this->llmStream(array_map(fn (string $piece) => ['content' => $piece], $pieces));
    }

    /**
     * @param array<string, array{string, string}> $calls tool call id => [tool name, JSON arguments]
     */
    private function llmToolCalls(array $calls): MockResponse
    {
        $deltas = [];
        foreach (array_keys($calls) as $index => $id) {
            [$name, $arguments] = $calls[$id];
            // Ollama sends a whole call at once; the format allows the arguments to come in pieces.
            [$start, $end] = str_split($arguments, (int) ceil(\strlen($arguments) / 2)) + [1 => ''];
            $deltas[] = ['tool_calls' => [['index' => $index, 'id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $start]]]];
            $deltas[] = ['tool_calls' => [['index' => $index, 'function' => ['arguments' => $end]]]];
        }

        return $this->llmStream($deltas);
    }

    private function sentMessages(MockResponse $response): array
    {
        return json_decode($response->getRequestOptions()['body'], true)['messages'];
    }
}
