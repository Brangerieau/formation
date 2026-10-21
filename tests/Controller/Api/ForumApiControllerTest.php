<?php

namespace App\Tests\Controller\Api;

use App\Entity\Post;
use App\Entity\Topic;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ForumApiControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCreateAndReadForumThroughApi(): void
    {
        $category = $this->request('POST', '/api/categories', ['name' => 'PHP'], 201);
        self::assertSame('PHP', $category['name']);
        self::assertSame([$category], $this->request('GET', '/api/categories'));

        $topic = $this->request('POST', "/api/categories/{$category['id']}/topics", ['title' => 'Symfony 8', 'content' => 'Quoi de neuf ?', 'author' => 'alice'], 201);
        self::assertSame('Symfony 8', $topic['title']);
        self::assertArrayNotHasKey('posts', $topic);
        self::assertSame([$topic], $this->request('GET', "/api/categories/{$category['id']}/topics"));

        $post = $this->request('POST', "/api/topics/{$topic['id']}/posts", ['content' => 'MapRequestPayload', 'author' => 'bob'], 201);
        self::assertSame('bob', $post['author']);

        $shown = $this->request('GET', "/api/topics/{$topic['id']}");
        self::assertSame('alice', $shown['author']);
        self::assertSame([$post], $shown['posts']);
    }

    public function testDeleteCascadesToChildren(): void
    {
        $category = $this->request('POST', '/api/categories', ['name' => 'PHP'], 201);
        $topic = $this->request('POST', "/api/categories/{$category['id']}/topics", ['title' => 't', 'content' => 'c', 'author' => 'alice'], 201);
        $post = $this->request('POST', "/api/topics/{$topic['id']}/posts", ['content' => 'p1', 'author' => 'bob'], 201);

        $this->delete("/api/posts/{$post['id']}");
        self::assertSame([], $this->request('GET', "/api/topics/{$topic['id']}")['posts']);

        $this->request('POST', "/api/topics/{$topic['id']}/posts", ['content' => 'p2', 'author' => 'bob'], 201);
        $this->delete("/api/topics/{$topic['id']}");
        $this->request('GET', "/api/topics/{$topic['id']}", expectedStatus: 404);
        self::assertSame(0, $this->countRows(Post::class));

        $topic = $this->request('POST', "/api/categories/{$category['id']}/topics", ['title' => 't', 'content' => 'c', 'author' => 'alice'], 201);
        $this->request('POST', "/api/topics/{$topic['id']}/posts", ['content' => 'p3', 'author' => 'bob'], 201);
        $this->delete("/api/categories/{$category['id']}");
        self::assertSame([], $this->request('GET', '/api/categories'));
        self::assertSame([0, 0], [$this->countRows(Topic::class), $this->countRows(Post::class)]);
    }

    public function testDeleteUnknownReturns404(): void
    {
        foreach (['/api/categories/999', '/api/topics/999', '/api/posts/999'] as $uri) {
            $this->request('DELETE', $uri, expectedStatus: 404);
        }
    }

    public function testInvalidPayloadReturns422(): void
    {
        $this->request('POST', '/api/categories', ['name' => ''], 422);
        $this->request('POST', '/api/categories', [], 422);
        $this->request('POST', '/api/categories', ['name' => str_repeat('a', 101)], 422);
    }

    public function testUnknownParentReturns404(): void
    {
        $this->request('GET', '/api/topics/999', expectedStatus: 404);
        $this->request('POST', '/api/categories/999/topics', ['title' => 't', 'content' => 'c', 'author' => 'a'], 404);
    }

    public function testSwaggerDocIsServed(): void
    {
        $this->client->request('GET', '/api/doc');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#swagger-ui');

        $spec = $this->request('GET', '/api/doc.json');
        self::assertArrayHasKey('/api/topics/{id}/posts', $spec['paths']);
        self::assertArrayHasKey('TopicWithPosts', $spec['components']['schemas']);
    }

    private function request(string $method, string $uri, ?array $payload = null, int $expectedStatus = 200): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: null === $payload ? null : json_encode($payload));
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    // 204 has no body, hence no JSON Content-Type to check.
    private function delete(string $uri): void
    {
        $this->client->request('DELETE', $uri);
        self::assertResponseStatusCodeSame(204);
    }

    private function countRows(string $entity): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository($entity)->count();
    }
}
