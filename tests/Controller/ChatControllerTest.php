<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class ChatControllerTest extends WebTestCase
{
    public function testConversationIsSentToTheLlmUntilReset(): void
    {
        $client = static::createClient();
        // The mock is set on this kernel: a reboot between requests would bring the real client back.
        $client->disableReboot();
        $llmResponses = [$this->llmReply('Salut !'), $this->llmReply('Rien de neuf.'), $this->llmReply('Re !')];
        static::getContainer()->set('ollama.client', new MockHttpClient($llmResponses));
        $systemPrompt = static::getContainer()->getParameter('app.chat.system_prompt');

        $this->chat($client, 'Bonjour');
        self::assertResponseIsSuccessful();
        self::assertSame(['reply' => 'Salut !'], json_decode($client->getResponse()->getContent(), true));
        self::assertStringEndsWith('/v1/chat/completions', $llmResponses[0]->getRequestUrl());
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
        ], $this->sentMessages($llmResponses[0]));

        $this->chat($client, 'Quoi de neuf ?');
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Salut !'],
            ['role' => 'user', 'content' => 'Quoi de neuf ?'],
        ], $this->sentMessages($llmResponses[1]));

        $client->request('GET', '/');
        self::assertSelectorCount(4, '#chat .chat-msg');
        self::assertSelectorTextSame('#chat .chat-msg-assistant:last-child', 'Rien de neuf.');

        $client->request('POST', '/chat/reset');
        self::assertResponseStatusCodeSame(204);

        $this->chat($client, 'Re');
        self::assertSame([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Re'],
        ], $this->sentMessages($llmResponses[2]));
    }

    public function testFailedLlmCallIsNotKeptInHistory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $llmResponses = [new JsonMockResponse([], ['http_code' => 500]), $this->llmReply('Salut !')];
        static::getContainer()->set('ollama.client', new MockHttpClient($llmResponses));

        $this->chat($client, 'Perdu');
        self::assertResponseStatusCodeSame(500);

        $this->chat($client, 'Bonjour');
        self::assertSame(['system', 'user'], array_column($this->sentMessages($llmResponses[1]), 'role'));
    }

    public function testInvalidMessageReturns422(): void
    {
        $client = static::createClient();
        foreach ([['message' => ''], [], ['message' => str_repeat('a', 2001)]] as $payload) {
            $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
            self::assertResponseStatusCodeSame(422);
        }
    }

    private function chat(KernelBrowser $client, string $message): void
    {
        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => $message]));
    }

    private function llmReply(string $content): JsonMockResponse
    {
        return new JsonMockResponse(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]]]);
    }

    private function sentMessages(JsonMockResponse $response): array
    {
        return json_decode($response->getRequestOptions()['body'], true)['messages'];
    }
}
