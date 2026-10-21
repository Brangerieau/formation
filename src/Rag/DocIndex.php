<?php

namespace App\Rag;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The markdown files of docs/ in Qdrant, one point per section, embedded by Ollama
 * through the OpenAI-compatible API, as the chatbot talks to it.
 */
class DocIndex
{
    private const string COLLECTION = 'docs';

    public function __construct(
        private HttpClientInterface $ollamaClient,
        private HttpClientInterface $qdrantClient,
        #[Autowire(env: 'OLLAMA_EMBEDDING_MODEL')]
        private string $model,
        #[Autowire('%kernel.project_dir%/docs')]
        private string $docsDir,
    ) {
    }

    /**
     * Rebuilds the collection from scratch: the corpus is small, there is no diff to keep.
     *
     * @param (\Closure(string): void)|null $onSection called with the title of each section once embedded
     *
     * @return int the number of sections indexed
     */
    public function index(?\Closure $onSection = null): int
    {
        $points = [];
        foreach (glob($this->docsDir.'/*.md') as $file) {
            foreach ($this->chunk(file_get_contents($file), basename($file)) as $chunk) {
                // The headings give the section its context: "Serveur MCP > Suppressions par nom".
                $points[] = ['id' => \count($points) + 1, 'vector' => $this->embed($chunk['title']."\n\n".$chunk['content']), 'payload' => $chunk];
                $onSection?->__invoke($chunk['title']);
            }
        }
        if (!$points) {
            throw new \RuntimeException(\sprintf('No markdown section found in %s.', $this->docsDir));
        }

        // Embeddings first: the collection only goes missing for the time of these three calls.
        // ponytail: searches fail in that window; index into a new collection and switch an alias if that matters.
        $this->qdrant('DELETE', '/collections/'.self::COLLECTION);
        $this->qdrant('PUT', '/collections/'.self::COLLECTION, ['vectors' => ['size' => \count($points[0]['vector']), 'distance' => 'Cosine']]);
        $this->qdrant('PUT', '/collections/'.self::COLLECTION.'/points?wait=true', ['points' => $points]);

        return \count($points);
    }

    /**
     * @param int $limit few by default: the sections go into the LLM context, which is slow to read on CPU
     *
     * @return list<array{source: string, title: string, content: string, score: float}> the closest sections, best first
     */
    public function search(string $query, int $limit = 3): array
    {
        $result = $this->qdrant('POST', '/collections/'.self::COLLECTION.'/points/query', [
            'query' => $this->embed($query),
            'limit' => $limit,
            'with_payload' => true,
        ]);

        return array_map(fn (array $point) => $point['payload'] + ['score' => $point['score']], $result['points']);
    }

    /**
     * One chunk per section of level 1 to 3, titled with the path of its headings.
     * Deeper headings stay in the text of their section; "#" lines in code blocks are not headings.
     *
     * @return list<array{source: string, title: string, content: string}>
     */
    public function chunk(string $markdown, string $source): array
    {
        $chunks = [];
        $headings = [];
        $lines = [];
        $fenced = false;
        $flush = function () use (&$chunks, &$headings, &$lines, $source) {
            $content = trim(implode("\n", $lines));
            if ('' !== $content) {
                $chunks[] = ['source' => $source, 'title' => implode(' > ', $headings ?: [$source]), 'content' => $content];
            }
            $lines = [];
        };

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $fenced = !$fenced;
            }
            if (!$fenced && preg_match('/^(#{1,3}) +(.+?) *$/', $line, $match)) {
                $flush();
                $headings = [...\array_slice($headings, 0, \strlen($match[1]) - 1), $match[2]];
                continue;
            }
            $lines[] = $line;
        }
        $flush();

        return $chunks;
    }

    /**
     * @return list<float>
     */
    private function embed(string $text): array
    {
        return $this->ollamaClient->request('POST', '/v1/embeddings', ['json' => [
            'model' => $this->model,
            'input' => $text,
        ]])->toArray()['data'][0]['embedding'];
    }

    private function qdrant(string $method, string $path, ?array $body = null): mixed
    {
        $response = $this->qdrantClient->request($method, $path, null === $body ? [] : ['json' => $body]);

        return $response->toArray()['result'];
    }
}
