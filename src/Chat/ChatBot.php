<?php

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the LLM through the OpenAI-compatible Chat Completions API (served by Ollama here).
 */
class ChatBot
{
    public function __construct(
        private HttpClientInterface $ollamaClient,
        #[Autowire(env: 'OLLAMA_MODEL')]
        private string $model,
        #[Autowire(param: 'app.chat.system_prompt')]
        private string $systemPrompt,
    ) {
    }

    public function reply(string $message): string
    {
        $response = $this->ollamaClient->request('POST', '/v1/chat/completions', ['json' => [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->systemPrompt],
                ['role' => 'user', 'content' => $message],
            ],
        ]]);

        return $response->toArray()['choices'][0]['message']['content'];
    }
}
