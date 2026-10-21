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

    /** @var list<array>|null points returned by the mocked Qdrant, null for a 404 (docs not indexed) */
    private ?array $docPoints = [];

    /** @var list<array> bodies of the Qdrant queries */
    private array $docQueries = [];

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
        self::assertSame(['type' => 'function', 'function' => [
            'name' => 'get_api_category_list',
            'description' => 'Lister toutes les catégories du forum',
            'parameters' => ['properties' => [], 'type' => 'object', 'required' => []],
        ]], json_decode($firstRequest, true)['tools'][0]);
        self::assertStringContainsString('"properties":{}', $firstRequest);
        self::assertSame([['name' => 'get_api_category_list', 'arguments' => []]], $this->mcpCalls);
        // tools/list and tools/call carry the trace id of this /chat request, so both sides log under it.
        self::assertCount(2, $this->mcpTraceIds);
        self::assertMatchesRegularExpression('/^X-Trace-Id: [0-9a-f]{8}$/', $this->mcpTraceIds[0]);
        self::assertSame($this->mcpTraceIds[0], $this->mcpTraceIds[1]);

        // Read-only tools can follow one another: they are offered again.
        $secondRequest = json_decode($llmResponses[1]->getRequestOptions()['body'], true);
        self::assertArrayHasKey('tools', $secondRequest);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Résultat de get_api_category_list'], $secondRequest['messages'][3]);

        // Tool calls stay in the history sent to the LLM, but not in the widget.
        $this->chat($client, 'Merci');
        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($this->sentMessages($llmResponses[2]), 'role'));
        $client->request('GET', '/');
        self::assertSelectorCount(4, '#chat .chat-msg');
        self::assertSelectorTextSame('#chat .chat-tool summary', 'Lister toutes les catégories du forum ok');
    }

    public function testDestructiveToolsWaitForConfirmation(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['delete_category', '{"name":"A"}'], 'call_2' => ['delete_category', '{"name":"B"}']]),
            $this->llmToolCalls(['call_3' => ['delete_category', '{"name":"B"}']]),
            $this->llmReply('B est supprimée.'),
        ];
        $client = $this->createChatClient($llmResponses);

        // Even the first delete waits, the next one isn't offered. The LLM isn't called again until the user decides:
        // the request checked after the confirmation is its second one.
        $events = $this->chat($client, 'Supprime les catégories A et B');
        self::assertSame(['tool', 'tool', 'done'], array_column($events, 0));
        self::assertSame([], $this->mcpCalls);
        $tools = new Crawler(implode('', array_column($events, 1)));
        self::assertSame('chat-tool-call_1', $tools->filter('.chat-tool-pending')->attr('id'));
        self::assertSame('Supprimer une catégorie (name: A) à confirmer', $tools->filter('.chat-tool-pending summary')->text());
        self::assertCount(2, $tools->filter('.chat-tool-pending button'));
        self::assertSame('Supprimer une catégorie (name: B) non exécuté', $tools->filter('.chat-tool-skipped summary')->text());

        // The waiting card survives a page load.
        $client->request('GET', '/');
        self::assertSelectorCount(2, '#chat .chat-tool-pending button');

        // Confirmed, the stored call runs as is, then the LLM goes on: its next delete waits again.
        $events = $this->chat($client, null, '/chat/confirm');
        self::assertSame(['tool', 'tool', 'done'], array_column($events, 0));
        self::assertSame('chat-tool-call_1', (new Crawler($events[0][1]))->filter('.chat-tool-ok')->attr('id'));
        self::assertSame('chat-tool-call_3', (new Crawler($events[1][1]))->filter('.chat-tool-pending')->attr('id'));
        self::assertSame([['name' => 'delete_category', 'arguments' => ['name' => 'A']]], $this->mcpCalls);
        $messages = $this->sentMessages($llmResponses[1]);
        self::assertSame(['system', 'user', 'assistant', 'tool', 'tool'], array_column($messages, 'role'));
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Résultat de delete_category'], $messages[3]);
        self::assertStringStartsWith('Non exécuté', $messages[4]['content']);

        $events = $this->chat($client, null, '/chat/confirm');
        self::assertSame(['tool', 'delta', 'done'], array_column($events, 0));
        self::assertSame(['A', 'B'], array_column(array_column($this->mcpCalls, 'arguments'), 'name'));

        $client->request('POST', '/chat/confirm');
        self::assertResponseStatusCodeSame(409);
    }

    public function testCreationsRunWithoutConfirmation(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['post_api_category_create', '{"name":"A"}'], 'call_2' => ['post_api_category_create', '{"name":"B"}']]),
            $this->llmReply('A et B sont créées.'),
        ];
        $client = $this->createChatClient($llmResponses);

        self::assertSame(['tool', 'tool', 'delta', 'done'], array_column($this->chat($client, 'Crée les catégories A et B'), 0));
        self::assertSame(['A', 'B'], array_column(array_column($this->mcpCalls, 'arguments'), 'name'));
    }

    public function testCancelledCallIsAcknowledgedWithoutAnotherAction(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['delete_category', '{"name":"A"}']]),
            $this->llmReply('D\'accord, je ne supprime pas A.'),
            $this->llmReply('De rien.'),
        ];
        $client = $this->createChatClient($llmResponses);
        $this->chat($client, 'Supprime la catégorie A');

        // The card is sent again with its new status, then the LLM answers, without tools to go on with.
        $events = $this->chat($client, null, '/chat/cancel');
        self::assertSame(['tool', 'delta', 'done'], array_column($events, 0));
        $tool = new Crawler($events[0][1]);
        self::assertSame('chat-tool-call_1', $tool->filter('.chat-tool-cancelled')->attr('id'));
        self::assertSame('Supprimer une catégorie (name: A) annulé', $tool->filter('summary')->text());
        $request = json_decode($llmResponses[1]->getRequestOptions()['body'], true);
        self::assertArrayNotHasKey('tools', $request);
        self::assertStringStartsWith('Annulé par l\'utilisateur', $request['messages'][3]['content']);
        self::assertSame([], $this->mcpCalls);

        $client->request('POST', '/chat/cancel');
        self::assertResponseStatusCodeSame(409);

        $this->chat($client, 'Merci');
        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($this->sentMessages($llmResponses[2]), 'role'));
    }

    public function testCancellationStandsWhenTheLlmFails(): void
    {
        $llmResponses = [$this->llmToolCalls(['call_1' => ['delete_category', '{"name":"A"}']]), new JsonMockResponse([], ['http_code' => 500])];
        $client = $this->createChatClient($llmResponses);
        $this->chat($client, 'Supprime la catégorie A');

        self::assertSame(['tool', 'error'], array_column($this->chat($client, null, '/chat/cancel'), 0));
        $client->request('GET', '/');
        self::assertSelectorExists('#chat-tool-call_1.chat-tool-cancelled');
    }

    public function testNewMessageCancelsTheWaitingCall(): void
    {
        $llmResponses = [$this->llmToolCalls(['call_1' => ['delete_category', '{"name":"A"}']]), $this->llmReply('Bonjour.')];
        $client = $this->createChatClient($llmResponses);
        $this->chat($client, 'Supprime la catégorie A');

        // The card is sent again with its new status, for the widget to replace.
        $events = $this->chat($client, 'Laisse tomber');
        self::assertSame(['tool', 'delta', 'done'], array_column($events, 0));
        self::assertSame('chat-tool-call_1', (new Crawler($events[0][1]))->filter('.chat-tool-cancelled')->attr('id'));
        self::assertStringStartsWith('Annulé par l\'utilisateur', $this->sentMessages($llmResponses[1])[3]['content']);
        self::assertSame([], $this->mcpCalls);
    }

    public function testReadOnlyToolsChainUntilTheLastRound(): void
    {
        $llmResponses = [
            ...array_map(fn (int $i) => $this->llmToolCalls(['call_'.$i => ['get_api_category_list', '{}']]), range(1, 4)),
            $this->llmReply('Voilà.'),
        ];
        $client = $this->createChatClient($llmResponses);

        self::assertSame(['tool', 'tool', 'tool', 'tool', 'delta', 'done'], array_column($this->chat($client, 'Liste'), 0));
        self::assertCount(4, $this->mcpCalls);
        // The last call goes without tools: the model has to answer.
        self::assertArrayHasKey('tools', json_decode($llmResponses[3]->getRequestOptions()['body'], true));
        self::assertArrayNotHasKey('tools', json_decode($llmResponses[4]->getRequestOptions()['body'], true));
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

    public function testCloseDocumentationSectionIsAddedWhileAnsweringTheMessage(): void
    {
        $llmResponses = [
            $this->llmToolCalls(['call_1' => ['get_api_category_list', '{}']]),
            $this->llmReply('Ouvrez le sujet puis remplissez le formulaire.'),
            $this->llmReply('De rien.'),
        ];
        $client = $this->createChatClient($llmResponses);
        $systemPrompt = static::getContainer()->getParameter('app.chat.system_prompt');
        $this->docPoints = [['id' => 1, 'score' => 0.71, 'payload' => ['source' => 'utilisation.md', 'title' => 'Guide > Répondre', 'content' => 'Remplissez le formulaire.']]];

        $this->chat($client, 'Comment répondre à un sujet ?');
        // Only the best section is asked for, for the user's message.
        self::assertSame(1, $this->docQueries[0]['limit']);
        // In every LLM call answering this message, tool rounds included.
        foreach (\array_slice($llmResponses, 0, 2) as $response) {
            $system = $this->sentMessages($response)[0]['content'];
            self::assertStringStartsWith($systemPrompt, $system);
            self::assertStringEndsWith("## Guide > Répondre\n\nRemplissez le formulaire.", $system);
        }

        // Not kept for the next message, whose section is too far.
        $this->docPoints[0]['score'] = 0.54;
        $this->chat($client, 'Merci');
        $messages = $this->sentMessages($llmResponses[2]);
        self::assertSame($systemPrompt, $messages[0]['content']);
        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant', 'user'], array_column($messages, 'role'));
    }

    public function testChatWorksWithoutTheDocumentationIndex(): void
    {
        $llmResponses = [$this->llmReply('Bonjour !')];
        $client = $this->createChatClient($llmResponses);
        $this->docPoints = null;

        self::assertSame([['delta', 'Bonjour !'], ['done', '']], $this->chat($client, 'Bonjour'));
        self::assertSame(static::getContainer()->getParameter('app.chat.system_prompt'), $this->sentMessages($llmResponses[0])[0]['content']);
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
        // The embeddings of the documentation search go through the same client as the LLM calls.
        static::getContainer()->set('ollama.client', new MockHttpClient(function (string $method, string $url) use (&$llmResponses) {
            return str_ends_with($url, '/v1/embeddings')
                ? new JsonMockResponse(['data' => [['embedding' => [0.1, 0.2]]]])
                : array_shift($llmResponses);
        }));
        static::getContainer()->set('qdrant.client', new MockHttpClient(function (string $method, string $url, array $options) {
            $this->docQueries[] = json_decode($options['body'], true);

            return null === $this->docPoints
                ? new JsonMockResponse(['status' => ['error' => 'Not found: Collection `docs` doesn\'t exist!']], ['http_code' => 404])
                : new JsonMockResponse(['result' => ['points' => $this->docPoints], 'status' => 'ok']);
        }));
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
                'annotations' => ['readOnlyHint' => true],
            ], [
                'name' => 'post_api_category_create',
                'description' => 'Créer une catégorie',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
            ], [
                'name' => 'delete_category',
                'description' => 'Supprimer une catégorie',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
            ]]]]);
        }));

        return $client;
    }

    /**
     * @return list<array{string, string}> the server-sent events, as [type, data]
     */
    private function chat(KernelBrowser $client, ?string $message, string $url = '/chat'): array
    {
        $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: null === $message ? null : json_encode(['message' => $message]));

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
