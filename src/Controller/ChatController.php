<?php

namespace App\Controller;

use App\Chat\ChatBot;
use App\Dto\ChatInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class ChatController extends AbstractController
{
    #[Route('/chat', name: 'chat', methods: ['POST'], format: 'json')]
    public function chat(#[MapRequestPayload] ChatInput $input, ChatBot $bot): JsonResponse
    {
        return $this->json(['reply' => $bot->reply($input->message)]);
    }

    #[Route('/chat/reset', name: 'chat_reset', methods: ['POST'])]
    public function reset(ChatBot $bot): Response
    {
        $bot->reset();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
