<?php

namespace App\Chat;

use App\Mcp\McpClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Talks to the LLM through the OpenAI-compatible Chat Completions API (served by Ollama here).
 * The conversation, tool calls included, is kept in the session and sent back in full on each message.
 * The LLM may run one MCP tool per user message, then has to answer in text.
 */
class ChatBot
{
    private const string HISTORY_KEY = 'chat_history';
    private const string SKIPPED_TOOL_CALL = 'Non exécuté : une seule action par message. Demande à l\'utilisateur s\'il veut la faire ensuite.';

    public function __construct(
        private HttpClientInterface $ollamaClient,
        private McpClient $mcp,
        private RequestStack $requestStack,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private string $model,
        #[Autowire(param: 'app.chat.system_prompt')]
        private string $systemPrompt,
    ) {
    }

    public function reply(string $message): string
    {
        $history = [...$this->history(), ['role' => 'user', 'content' => $message]];

        $tools = array_map(fn (array $tool) => ['type' => 'function', 'function' => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            // Decoded {} comes back as []: cast so a tool without parameters still sends "properties": {}.
            'parameters' => ['properties' => (object) ($tool['inputSchema']['properties'] ?? [])] + $tool['inputSchema'],
        ]], $this->mcp->tools());
        $answer = $this->complete($history, $tools);

        if ($answer['tool_calls'] ?? []) {
            $history[] = ['role' => 'assistant', 'content' => $answer['content'] ?? '', 'tool_calls' => $answer['tool_calls']];
            foreach ($answer['tool_calls'] as $i => $toolCall) {
                $history[] = ['role' => 'tool', 'tool_call_id' => $toolCall['id'], 'content' => 0 === $i ? $this->callTool($toolCall['function']) : self::SKIPPED_TOOL_CALL];
            }
            // Without tools, the model has to answer: further actions wait for the user's go-ahead.
            $answer = $this->complete($history);
        }

        // Saved only on success: a failed call leaves no unanswered message in the history.
        $history[] = ['role' => 'assistant', 'content' => $answer['content']];
        $this->requestStack->getSession()->set(self::HISTORY_KEY, $history);

        return $answer['content'];
    }

    /**
     * @return list<array{role: 'user'|'assistant'|'tool', content: string, tool_calls?: list<array>, tool_call_id?: string}>
     */
    #[AsTwigFunction('chat_history')]
    public function history(): array
    {
        // The widget is on every page: don't start a session just to find it empty.
        $request = $this->requestStack->getCurrentRequest();

        return $request?->hasPreviousSession() ? $request->getSession()->get(self::HISTORY_KEY, []) : [];
    }

    public function reset(): void
    {
        $this->requestStack->getSession()->remove(self::HISTORY_KEY);
    }

    /**
     * @return array the assistant message
     */
    private function complete(array $history, array $tools = []): array
    {
        $response = $this->ollamaClient->request('POST', '/v1/chat/completions', ['json' => [
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $this->systemPrompt], ...$history],
        ] + ($tools ? ['tools' => $tools] : [])]);

        return $response->toArray()['choices'][0]['message'];
    }

    private function callTool(array $function): string
    {
        try {
            $arguments = json_decode($function['arguments'], true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'Erreur : arguments JSON invalides.';
        }

        return $this->mcp->call($function['name'], $arguments ?? []);
    }
}
