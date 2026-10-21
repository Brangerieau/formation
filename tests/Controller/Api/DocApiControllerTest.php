<?php

namespace App\Tests\Controller\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class DocApiControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var list<array> bodies of the Qdrant queries */
    private array $queries = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set('ollama.client', new MockHttpClient(new JsonMockResponse(['data' => [['embedding' => [0.1, 0.2]]]])));
        static::getContainer()->set('qdrant.client', new MockHttpClient(function (string $method, string $url, array $options) {
            $this->queries[] = json_decode($options['body'], true);

            return new JsonMockResponse(['result' => ['points' => [
                ['id' => 2, 'score' => 0.66, 'payload' => ['source' => 'utilisation.md', 'title' => "Guide d'utilisation > Répondre à un sujet", 'content' => 'Cliquez sur **Envoyer**.']],
            ]], 'status' => 'ok']);
        }));
    }

    public function testSearchReturnsTheClosestSections(): void
    {
        $this->client->request('GET', '/api/docs/search', ['q' => 'Comment répondre ?']);

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'source' => 'utilisation.md',
            'title' => "Guide d'utilisation > Répondre à un sujet",
            'content' => 'Cliquez sur **Envoyer**.',
            'score' => 0.66,
        ]], json_decode($this->client->getResponse()->getContent(), true));
        // Sent to the LLM as is: accents unescaped.
        self::assertStringContainsString('Répondre à un sujet', $this->client->getResponse()->getContent());
        self::assertSame(3, $this->queries[0]['limit']);
    }

    public function testInvalidQueryReturns422(): void
    {
        foreach ([[], ['q' => ''], ['q' => str_repeat('x', 501)]] as $query) {
            $this->client->request('GET', '/api/docs/search', $query);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], $this->queries);
    }
}
