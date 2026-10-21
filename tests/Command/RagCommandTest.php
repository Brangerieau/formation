<?php

namespace App\Tests\Command;

use App\Rag\DocIndex;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class RagCommandTest extends KernelTestCase
{
    /** @var list<string> input of each embedding request */
    private array $embedded = [];

    /** @var list<array{string, string, array|null}> method, path and body of each Qdrant request */
    private array $qdrantCalls = [];

    public function testChunksBySectionUnderTheirHeadings(): void
    {
        $markdown = <<<'MD'
            # Guide

            Intro.

            ## Installation

            ```bash
            # Not a heading
            make install
            ```

            ## Vide

            ### Détails

            Texte.

            #### Sous-détail

            Reste dans Détails.

            ## Fin
            Dernière ligne.
            MD;

        self::assertSame([
            ['source' => 'guide.md', 'title' => 'Guide', 'content' => 'Intro.'],
            ['source' => 'guide.md', 'title' => 'Guide > Installation', 'content' => "```bash\n# Not a heading\nmake install\n```"],
            ['source' => 'guide.md', 'title' => 'Guide > Vide > Détails', 'content' => "Texte.\n\n#### Sous-détail\n\nReste dans Détails."],
            ['source' => 'guide.md', 'title' => 'Guide > Fin', 'content' => 'Dernière ligne.'],
        ], static::getContainer()->get(DocIndex::class)->chunk($markdown, 'guide.md'));
    }

    public function testIndexRebuildsTheCollectionFromTheDocs(): void
    {
        $tester = $this->command('app:rag:index');
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $index = static::getContainer()->get(DocIndex::class);
        $chunks = [];
        foreach (glob(static::getContainer()->getParameter('kernel.project_dir').'/docs/*.md') as $file) {
            $chunks = [...$chunks, ...$index->chunk(file_get_contents($file), basename($file))];
        }
        self::assertNotEmpty($chunks);
        self::assertStringContainsString(\sprintf('%d sections indexées.', \count($chunks)), $tester->getDisplay());
        self::assertSame($chunks[0]['title']."\n\n".$chunks[0]['content'], $this->embedded[0]);

        [$delete, $create, $upsert] = $this->qdrantCalls;
        self::assertSame(['DELETE', '/collections/docs', null], $delete);
        // The vector size comes from the model, not from the configuration.
        self::assertSame(['PUT', '/collections/docs', ['vectors' => ['size' => 3, 'distance' => 'Cosine']]], $create);
        self::assertSame(['PUT', '/collections/docs/points?wait=true'], \array_slice($upsert, 0, 2));
        self::assertCount(\count($chunks), $upsert[2]['points']);
        self::assertSame(['id' => 1, 'vector' => [0.1, 0.2, 0.3], 'payload' => $chunks[0]], $upsert[2]['points'][0]);
    }

    public function testSearchShowsTheClosestSectionsFirst(): void
    {
        $tester = $this->command('app:rag:search');
        $tester->execute(['query' => 'Comment répondre ?', '--limit' => '2']);
        $tester->assertCommandIsSuccessful();

        self::assertSame(['Comment répondre ?'], $this->embedded);
        self::assertSame([['POST', '/collections/docs/points/query', ['query' => [0.1, 0.2, 0.3], 'limit' => 2, 'with_payload' => true]]], $this->qdrantCalls);
        self::assertMatchesRegularExpression('/0\.812 +Guide > Répondre \(utilisation\.md\).*Remplissez le formulaire\..*0\.405 +Guide > Accueil/s', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        $kernel = self::bootKernel();
        static::getContainer()->set('ollama.client', new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertStringEndsWith('/v1/embeddings', $url);
            $body = json_decode($options['body'], true);
            self::assertSame('bge-m3', $body['model']);
            $this->embedded[] = $body['input'];

            return new JsonMockResponse(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]);
        }));
        static::getContainer()->set('qdrant.client', new MockHttpClient(function (string $method, string $url, array $options) {
            $this->qdrantCalls[] = [$method, substr($url, \strlen('http://qdrant:6333')), isset($options['body']) ? json_decode($options['body'], true) : null];

            return new JsonMockResponse(['result' => str_ends_with($url, '/points/query') ? ['points' => [
                ['id' => 3, 'score' => 0.8123, 'payload' => ['source' => 'utilisation.md', 'title' => 'Guide > Répondre', 'content' => 'Remplissez le formulaire.']],
                ['id' => 1, 'score' => 0.4051, 'payload' => ['source' => 'utilisation.md', 'title' => 'Guide > Accueil', 'content' => 'Liste des catégories.']],
            ]] : true, 'status' => 'ok']);
        }));

        return new CommandTester((new Application($kernel))->find($name));
    }
}
