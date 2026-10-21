<?php

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Talks to the LLM through the OpenAI-compatible Chat Completions API (served by Ollama here).
 * The conversation is kept in the session and sent back in full on each message.
 */
class ChatBot
{
    private const string HISTORY_KEY = 'chat_history';

    public function __construct(
        private HttpClientInterface $ollamaClient,
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

        $response = $this->ollamaClient->request('POST', '/v1/chat/completions', ['json' => [
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $this->systemPrompt], ...$history],
        ]]);
        $reply = $response->toArray()['choices'][0]['message']['content'];

        // Saved only on success: a failed call leaves no unanswered message in the history.
        $this->requestStack->getSession()->set(self::HISTORY_KEY, [...$history, ['role' => 'assistant', 'content' => $reply]]);

        return $reply;
    }

    /**
     * @return list<array{role: 'user'|'assistant', content: string}>
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
}
