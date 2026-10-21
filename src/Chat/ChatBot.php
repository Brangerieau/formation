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
 * and sent back in full on each message. Answers are streamed: the session is already saved when the conversation is, hence the cache.
 *
 * Destructive MCP tools (deletes, changes) wait for the user to confirm them in the widget, one at a time,
 * and are then run as stored, not asked again to the LLM: a small model may otherwise claim it did it without calling the tool.
 * The other tools run freely.
 */
#[WithMonologChannel('chat')]
class ChatBot
{
    private const string CONVERSATION_KEY = 'chat_conversation';
    // Browser-session cookie, no expiry of its own: an abandoned conversation goes after a day.
    private const int CONVERSATION_TTL = 86400;
    // LLM calls per message, the last one without tools so that the model has to answer.
    private const int MAX_ROUNDS = 5;
    private const string PENDING = 'En attente de confirmation par l\'utilisateur.';
    private const string SKIPPED = 'Non exécuté : une seule action à la fois attend la confirmation de l\'utilisateur.';
    private const string CANCELLED = 'Annulé par l\'utilisateur.';

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
     * the returned generator runs as the response streams. A call waiting for confirmation is cancelled.
     *
     * @return \Generator<array> what this message adds to the conversation, as it comes: tool calls as transcript items,
     *                           then the answer as {type: 'delta', content: string} pieces
     */
    public function reply(string $message): \Generator
    {
        $this->logger->info('Message reçu', ['message' => $message]);
        $conversation = $this->conversation();
        $history = $conversation->get() ?? [];
        $cancelled = $this->cancelPending($history);

        return $this->converse($conversation, [...$history, ['role' => 'user', 'content' => $message]], $cancelled);
    }

    /**
     * Runs the destructive call waiting for confirmation, then lets the LLM go on.
     *
     * @return \Generator<array>|null as reply(), starting with the confirmed tool call; null when nothing waits
     */
    public function confirm(): ?\Generator
    {
        $conversation = $this->conversation();
        $history = $conversation->get() ?? [];
        $index = $this->pendingIndex($history);
        if (null === $index) {
            return null;
        }
        $this->logger->info('Action confirmée', ['tool' => $history[$index]['tool_call']['function']['name']]);

        return $this->converse($conversation, $history, confirmed: $index);
    }

    /**
     * @return array|null the cancelled tool call as a transcript item; null when nothing waits
     */
    public function cancel(): ?array
    {
        $conversation = $this->conversation();
        $history = $conversation->get() ?? [];
        $cancelled = $this->cancelPending($history);
        if (null === $cancelled) {
            return null;
        }
        $this->cache->save($conversation->set($history)->expiresAfter(self::CONVERSATION_TTL));

        return $this->transcript([$cancelled])[0];
    }

    /**
     * @param array|null $cancelled a call cancelled by this message, shown again with its new status
     * @param int|null   $confirmed index in $history of the confirmed call to run first
     */
    private function converse(CacheItemInterface $conversation, array $history, ?array $cancelled = null, ?int $confirmed = null): \Generator
    {
        $start = microtime(true);
        if (null !== $cancelled) {
            yield from $this->transcript([$cancelled]);
        }

        $tools = $this->mcp->tools();
        $this->logger->info('Tools disponibles', ['count' => \count($tools)]);
        $labels = array_column($tools, 'description', 'name');
        // MCP defaults: a tool without hints may write, and destructively.
        $destructive = array_combine(array_column($tools, 'name'), array_map(
            fn (array $tool) => !($tool['annotations']['readOnlyHint'] ?? false) && ($tool['annotations']['destructiveHint'] ?? true),
            $tools,
        ));
        $definitions = array_map(fn (array $tool) => ['type' => 'function', 'function' => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            // Decoded {} comes back as []: cast so a tool without parameters still sends "properties": {}.
            'parameters' => ['properties' => (object) ($tool['inputSchema']['properties'] ?? [])] + $tool['inputSchema'],
        ]], $tools);

        if (null !== $confirmed) {
            $history[$confirmed] = $this->runTool($history[$confirmed]['tool_call'], $labels, 'ok');
            yield from $this->transcript([$history[$confirmed]]);
        }

        $pending = false;
        for ($round = 1; ; ++$round) {
            $answer = yield from $this->complete($history, self::MAX_ROUNDS === $round ? [] : $definitions);
            if (!($answer['tool_calls'] ?? [])) {
                break;
            }

            $history[] = ['role' => 'assistant', 'content' => $answer['content'] ?? '', 'tool_calls' => $answer['tool_calls']];
            foreach ($answer['tool_calls'] as $toolCall) {
                $mode = match (true) {
                    !($destructive[$toolCall['function']['name']] ?? true) => 'ok',
                    !$pending => 'pending',
                    default => 'skipped',
                };
                $pending = $pending || 'pending' === $mode;
                $history[] = $toolMessage = $this->runTool($toolCall, $labels, $mode);
                yield from $this->transcript([$toolMessage]);
            }
            // The widget asks the user: the LLM goes on once they confirm.
            if ($pending) {
                break;
            }
        }

        // Saved only on success: a failed call leaves no unanswered message in the history.
        if (!$pending) {
            $history[] = ['role' => 'assistant', 'content' => $answer['content']];
        }
        $this->cache->save($conversation->set($history)->expiresAfter(self::CONVERSATION_TTL));
        $this->logger->info('Réponse envoyée', ['total_ms' => (int) ((microtime(true) - $start) * 1000)]);
    }

    /**
     * The conversation as shown in the widget: messages and tool calls, in order.
     *
     * @param list<array>|null $messages defaults to the whole history
     *
     * @return list<array{type: 'message', role: string, content: string}|array{type: 'tool', id: string, label: string, arguments: string, status: 'ok'|'error'|'pending'|'skipped'|'cancelled', result: string}>
     */
    #[AsTwigFunction('chat_transcript')]
    public function transcript(?array $messages = null): array
    {
        $items = [];
        foreach ($messages ?? $this->history() as $message) {
            if ('tool' === $message['role']) {
                $result = json_decode($message['content'], true);
                $items[] = ['type' => 'tool', 'id' => $message['tool_call_id'], 'result' => null === $result ? $message['content'] : json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)] + $message['display'];
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

    private function conversation(): CacheItemInterface
    {
        $session = $this->requestStack->getSession();
        if (!$session->has(self::CONVERSATION_KEY)) {
            $session->set(self::CONVERSATION_KEY, bin2hex(random_bytes(8)));
        }

        return $this->cache->getItem($this->cacheKey($session->get(self::CONVERSATION_KEY)));
    }

    private function pendingIndex(array $history): ?int
    {
        return array_key_last(array_filter($history, fn (array $message) => isset($message['tool_call'])));
    }

    /**
     * @return array|null the cancelled tool message, now in $history; null when nothing waits
     */
    private function cancelPending(array &$history): ?array
    {
        $index = $this->pendingIndex($history);
        if (null === $index) {
            return null;
        }
        $this->logger->info('Action annulée', ['tool' => $history[$index]['tool_call']['function']['name']]);
        unset($history[$index]['tool_call']);
        $history[$index]['content'] = self::CANCELLED;
        $history[$index]['display']['status'] = 'cancelled';

        return $history[$index];
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
                ...array_map(fn (array $message) => array_diff_key($message, ['display' => true, 'tool_call' => true]), $history),
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
     * @param array<string, string>    $labels tool name => human-readable description
     * @param 'ok'|'pending'|'skipped' $mode   ok runs the tool, the others only record it
     *
     * @return array the tool message for the LLM, with what the widget shows under "display",
     *               and the call to run on confirmation under "tool_call" while it waits
     */
    private function runTool(array $toolCall, array $labels, string $mode): array
    {
        $name = $toolCall['function']['name'];
        try {
            $arguments = json_decode($toolCall['function']['arguments'], true, flags: \JSON_THROW_ON_ERROR) ?? [];
            $result = 'ok' === $mode ? $this->mcp->call($name, $arguments) : null;
        } catch (\JsonException) {
            $arguments = [];
            $result = ['text' => 'arguments JSON invalides.', 'isError' => true];
        }

        [$status, $content] = match (true) {
            null === $result => [$mode, 'pending' === $mode ? self::PENDING : self::SKIPPED],
            $result['isError'] => ['error', 'Erreur : '.$result['text']],
            default => ['ok', $result['text']],
        };
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
        ] + ('pending' === $status ? ['tool_call' => $toolCall] : []);
    }
}
