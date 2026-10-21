<?php

namespace App\Controller;

use App\Chat\ChatBot;
use App\Dto\ChatInput;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    // Streams the new tool calls as HTML, rendered with the same partial as the history, then the answer as text pieces,
    // then "done". The 200 status is sent before the LLM answers: a failure comes as an "error" event instead.
    #[Route('/chat', name: 'chat', methods: ['POST'])]
    public function chat(#[MapRequestPayload] ChatInput $input, ChatBot $bot, LoggerInterface $chatLogger): EventStreamResponse
    {
        // Up to two LLM calls per message, each allowed 120 s by ollama.client (CPU only).
        set_time_limit(240);
        $reply = $bot->reply($input->message);

        return new EventStreamResponse(function () use ($reply, $chatLogger) {
            try {
                foreach ($reply as $item) {
                    yield 'delta' === $item['type']
                        ? new ServerEvent($item['content'], type: 'delta')
                        : new ServerEvent($this->renderView('chat/_transcript.html.twig', ['items' => [$item]]), type: 'tool');
                }
                yield new ServerEvent('', type: 'done');
            } catch (\Throwable $e) {
                $chatLogger->error('Échec de la réponse', ['exception' => $e]);
                yield new ServerEvent('', type: 'error');
            }
        });
    }

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(ChatBot $bot): Response
    {
        $bot->reset();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
