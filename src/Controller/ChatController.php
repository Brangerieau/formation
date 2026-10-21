<?php

namespace App\Controller;

use App\Chat\ChatBot;
use App\Dto\ChatInput;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    // Streams the new tool calls as HTML, rendered with the same partial as the history, then the answer as text pieces,
    // then "done". The 200 status is sent before the LLM answers: a failure comes as an "error" event instead.
    #[Route('/chat', name: 'chat', methods: ['POST'])]
    public function chat(#[MapRequestPayload] ChatInput $input, ChatBot $bot, LoggerInterface $chatLogger): EventStreamResponse
    {
        return $this->eventStream($bot->reply($input->message), $chatLogger);
    }

    // Runs the destructive call waiting for confirmation, streamed as /chat.
    #[Route('/chat/confirm', name: 'chat_confirm', methods: ['POST'])]
    public function confirm(ChatBot $bot, LoggerInterface $chatLogger): EventStreamResponse
    {
        return $this->eventStream($bot->confirm() ?? throw new ConflictHttpException('Aucune action en attente.'), $chatLogger);
    }

    // Returns the cancelled action, rendered as in the history.
    #[Route('/chat/cancel', name: 'chat_cancel', methods: ['POST'])]
    public function cancel(ChatBot $bot): Response
    {
        return $this->render('chat/_transcript.html.twig', ['items' => [$bot->cancel() ?? throw new ConflictHttpException('Aucune action en attente.')]]);
    }

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(ChatBot $bot): Response
    {
        $bot->reset();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function eventStream(\Generator $reply, LoggerInterface $chatLogger): EventStreamResponse
    {
        // Up to ChatBot::MAX_ROUNDS LLM calls, each allowed 120 s by ollama.client (CPU only).
        set_time_limit(600);

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
}
