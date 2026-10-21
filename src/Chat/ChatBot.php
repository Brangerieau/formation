<?php

namespace App\Chat;

use App\Mcp\McpClient;
use Monolog\Attribute\WithMonologChannel;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Talks to the LLM through the OpenAI-compatible Chat Completions API (served by Ollama here).
 * The conversation, tool calls included, is kept in the cache under an id stored in the session,
 * and sent back in full on each message. The LLM may run one MCP tool per user message, then has to answer in text.
 * Answers are streamed: the session is already saved when the conversation is, hence the cache.
 */
#[WithMonologChannel('chat')]
class ChatBot
{
    private const string CONVERSATION_KEY = 'chat_conversation';
    // Browser-session cookie, no expiry of its own: an abandoned conversation goes after a day.
    private const int CONVERSATION_TTL = 86400;
    private const string SKIPPED_TOOL_CALL = 'Non exécuté : une seule action par message. Demande à l\'utilisateur s\'il veut la faire ensuite.';

    public function __construct(
        private HttpClientInterface $ollamaClient,
        private McpClient $mcp,
        private RequestStack $requestStack,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private string $model,
        #[Autowire(param: 'app.chat.system_prompt')]
        private string $systemPrompt,
    ) {
    }

    /**
     * Reads and writes the session right away, while the response headers can still carry its cookie:
     * the returned generator runs as the response streams.
     *
     * @return \Generator<array> what this message adds to the conversation, as it comes: tool calls as transcript items,
     *                           then the answer as {type: 'delta', content: string} pieces
     */
    public function reply(string $message): \Generator
    {
        $session = $this->requestStack->getSession();
        if (!$session->has(self::CONVERSATION_KEY)) {
            $session->set(self::CONVERSATION_KEY, bin2hex(random_bytes(8)));
        }

        return $this->converse($this->cache->getItem($this->cacheKey($session->get(self::CONVERSATION_KEY))), $message);
    }

    private function converse(CacheItemInterface $conversation, string $message): \Generator
    {
        $start = microtime(true);
        $this->logger->info('Message reçu', ['message' => $message]);
        $history = [...$conversation->get() ?? [], ['role' => 'user', 'content' => $message]];

        $tools = $this->mcp->tools();
        $this->logger->info('Tools disponibles', ['count' => \count($tools)]);
        $answer = yield from $this->complete($history, array_map(fn (array $tool) => ['type' => 'function', 'function' => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            // Decoded {} comes back as []: cast so a tool without parameters still sends "properties": {}.
            'parameters' => ['properties' => (object) ($tool['inputSchema']['properties'] ?? [])] + $tool['inputSchema'],
        ]], $tools));

        if ($answer['tool_calls'] ?? []) {
            $labels = array_column($tools, 'description', 'name');
            $history[] = ['role' => 'assistant', 'content' => $answer['content'] ?? '', 'tool_calls' => $answer['tool_calls']];
            foreach ($answer['tool_calls'] as $i => $toolCall) {
                $history[] = $toolMessage = $this->runTool($toolCall, $labels, 0 === $i);
                yield from $this->transcript([$toolMessage]);
            }
            // Without tools, the model has to answer: further actions wait for the user's go-ahead.
            $answer = yield from $this->complete($history);
        }

        // Saved only on success: a failed call leaves no unanswered message in the history.
        $history[] = ['role' => 'assistant', 'content' => $answer['content']];
        $this->cache->save($conversation->set($history)->expiresAfter(self::CONVERSATION_TTL));
        $this->logger->info('Réponse envoyée', ['total_ms' => (int) ((microtime(true) - $start) * 1000)]);
    }

    /**
     * The conversation as shown in the widget: messages and tool calls, in order.
     *
     * @param list<array>|null $messages defaults to the whole history
     *
     * @return list<array{type: 'message', role: string, content: string}|array{type: 'tool', label: string, arguments: string, status: 'ok'|'error'|'skipped', result: string}>
     */
    #[AsTwigFunction('chat_transcript')]
    public function transcript(?array $messages = null): array
    {
        $items = [];
        foreach ($messages ?? $this->history() as $message) {
            if ('tool' === $message['role']) {
                $result = json_decode($message['content'], true);
                $items[] = ['type' => 'tool', 'result' => null === $result ? $message['content'] : json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)] + $message['display'];
            } elseif ('' !== ($message['content'] ?? '')) {
                $items[] = ['type' => 'message', 'role' => $message['role'], 'content' => $message['content']];
            }
        }

        return $items;
    }

    public function reset(): void
    {
        $id = $this->requestStack->getSession()->remove(self::CONVERSATION_KEY);
        if (null !== $id) {
            $this->cache->deleteItem($this->cacheKey($id));
        }
    }

    /**
     * @return list<array> messages in the Chat Completions format, plus a "display" key on tool results
     */
    private function history(): array
    {
        // The widget is on every page: don't start a session just to find it empty.
        $request = $this->requestStack->getCurrentRequest();
        $id = $request?->hasPreviousSession() ? $request->getSession()->get(self::CONVERSATION_KEY) : null;

        return null === $id ? [] : $this->cache->getItem($this->cacheKey($id))->get() ?? [];
    }

    private function cacheKey(string $conversationId): string
    {
        return 'chat_history_'.$conversationId;
    }

    /**
     * @return \Generator<array{type: 'delta', content: string}, mixed, mixed, array> the answer as it is written,
     *                                                                              returning the assistant message
     */
    private function complete(array $history, array $tools = []): \Generator
    {
        $response = $this->ollamaClient->request('POST', '/v1/chat/completions', ['json' => [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ...array_map(fn (array $message) => array_diff_key($message, ['display' => true]), $history),
            ],
            'stream' => true,
        ] + ($tools ? ['tools' => $tools] : [])]);
        // Throws on an HTTP error status, before anything is yielded.
        $response->getHeaders();

        $message = ['role' => 'assistant', 'content' => ''];
        $buffer = '';
        foreach ($this->ollamaClient->stream($response) as $chunk) {
            // One "data: {json}" line per event; the last line may still be incomplete.
            $lines = explode("\n", $buffer.$chunk->getContent());
            $buffer = array_pop($lines);
            foreach ($lines as $line) {
                if (!str_starts_with($line, 'data: {')) {
                    continue;
                }
                $delta = json_decode(substr($line, 6), true, flags: \JSON_THROW_ON_ERROR)['choices'][0]['delta'] ?? [];
                if ('' !== ($delta['content'] ?? '')) {
                    $message['content'] .= $delta['content'];
                    yield ['type' => 'delta', 'content' => $delta['content']];
                }
                // The first piece of a tool call carries its id and name, the next ones more of its arguments.
                foreach ($delta['tool_calls'] ?? [] as $piece) {
                    $message['tool_calls'][$piece['index']] ??= ['id' => $piece['id'], 'type' => 'function', 'function' => ['name' => $piece['function']['name'], 'arguments' => '']];
                    $message['tool_calls'][$piece['index']]['function']['arguments'] .= $piece['function']['arguments'] ?? '';
                }
            }
        }

        $this->logger->info('Réponse du LLM', [
            'tools' => \count($tools),
            'ms' => (int) ($response->getInfo('total_time') * 1000),
            'tool_calls' => array_map(fn (array $toolCall) => $toolCall['function']['name'], $message['tool_calls'] ?? []),
            'reply' => $message['content'] ?? '',
        ]);

        return $message;
    }

    /**
     * @param array<string, string> $labels tool name => human-readable description
     *
     * @return array the tool message for the LLM, with what the widget shows under "display"
     */
    private function runTool(array $toolCall, array $labels, bool $allowed): array
    {
        $name = $toolCall['function']['name'];
        try {
            $arguments = json_decode($toolCall['function']['arguments'], true, flags: \JSON_THROW_ON_ERROR) ?? [];
            $result = $allowed ? $this->mcp->call($name, $arguments) : null;
        } catch (\JsonException) {
            $arguments = [];
            $result = ['text' => 'arguments JSON invalides.', 'isError' => true];
        }

        $content = match (true) {
            null === $result => self::SKIPPED_TOOL_CALL,
            $result['isError'] => 'Erreur : '.$result['text'],
            default => $result['text'],
        };
        $status = null === $result ? 'skipped' : ($result['isError'] ? 'error' : 'ok');
        $this->logger->info('Résultat du tool', ['tool' => $name, 'arguments' => $arguments, 'status' => $status]);

        return [
            'role' => 'tool',
            'tool_call_id' => $toolCall['id'],
            'content' => $content,
            'display' => [
                'label' => $labels[$name] ?? $name,
                'arguments' => implode(', ', array_map(
                    fn (string $key, mixed $value) => $key.': '.(\is_string($value) ? $value : json_encode($value, \JSON_UNESCAPED_UNICODE)),
                    array_keys($arguments),
                    $arguments,
                )),
                'status' => $status,
            ],
        ];
    }
}
