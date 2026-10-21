<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ChatControllerTest extends WebTestCase
{
    public function testChatReplies(): void
    {
        $client = static::createClient();
        $client->request('POST', '/chat', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['message' => 'Bonjour']));

        self::assertResponseIsSuccessful();
        self::assertSame(['reply' => 'test'], json_decode($client->getResponse()->getContent(), true));
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
