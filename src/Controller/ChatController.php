<?php

namespace App\Controller;

use App\Chat\ChatBot;
use App\Dto\ChatInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    // Returns the HTML of the new messages and tool calls: the widget renders them with the same partial.
    #[Route('/chat', name: 'chat', methods: ['POST'])]
    public function chat(#[MapRequestPayload] ChatInput $input, ChatBot $bot): Response
    {
        // Up to two LLM calls per message, each allowed 120 s by ollama.client (CPU only).
        set_time_limit(240);

        return $this->render('chat/_transcript.html.twig', ['items' => $bot->reply($input->message)]);
    }

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(ChatBot $bot): Response
    {
        $bot->reset();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
