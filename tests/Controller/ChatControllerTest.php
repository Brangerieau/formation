<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class ChatControllerTest extends WebTestCase
{
    public function testChatReplies(): void
    {
        $client = static::createClient();
        $llmResponse = new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Salut !']]]]);
        static::getContainer()->set('ollama.client', new MockHttpClient($llmResponse));

        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => 'Bonjour']));

        self::assertResponseIsSuccessful();
        self::assertSame(['reply' => 'Salut !'], json_decode($client->getResponse()->getContent(), true));

        $sent = json_decode($llmResponse->getRequestOptions()['body'], true);
        self::assertStringEndsWith('/v1/chat/completions', $llmResponse->getRequestUrl());
        self::assertSame(['system', 'user'], array_column($sent['messages'], 'role'));
        self::assertSame('Bonjour', $sent['messages'][1]['content']);
        self::assertSame(static::getContainer()->getParameter('app.chat.system_prompt'), $sent['messages'][0]['content']);
    }

    public function testInvalidMessageReturns422(): void
    {
        $client = static::createClient();
        foreach ([['message' => ''], [], ['message' => str_repeat('a', 2001)]] as $payload) {
            $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
            self::assertResponseStatusCodeSame(422);
        }
    }
}
