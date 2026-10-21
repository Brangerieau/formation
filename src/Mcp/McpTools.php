<?php

namespace App\Mcp;

/**
 * The tools served by /mcp: the generated API tools, except that deletes take names instead of ids.
 * The model can't know ids without an extra call, so these tools look them up through the API themselves.
 */
class McpTools
{
    // Replaced by the delete_* tools below.
    private const string HIDDEN_PREFIX = 'delete_api_';
    private const int MAX_CANDIDATES = 10;

    public function __construct(
        private ApiToolRegistry $api,
    ) {
    }

    /**
     * @return list<array> MCP tool definitions, as returned by tools/list
     */
    public function definitions(): array
    {
        $destructive = ['readOnlyHint' => false, 'destructiveHint' => true];

        return [
            [
                'name' => 'delete_category',
                'description' => 'Supprimer définitivement une catégorie par son nom, avec ses sujets et leurs messages',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Nom de la catégorie'],
                ], 'required' => ['name']],
                'annotations' => $destructive,
            ],
            [
                'name' => 'delete_topic',
                'description' => 'Supprimer définitivement un sujet par son titre, avec ses messages',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Titre du sujet, ou un extrait du titre'],
                ], 'required' => ['title']],
                'annotations' => $destructive,
            ],
            [
                'name' => 'delete_post',
                'description' => "Supprimer définitivement un message d'un sujet, désigné par son auteur et/ou un extrait",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'topic' => ['type' => 'string', 'description' => 'Titre du sujet, ou un extrait du titre'],
                    'author' => ['type' => 'string', 'description' => 'Auteur du message'],
                    'excerpt' => ['type' => 'string', 'description' => 'Extrait du contenu du message'],
                ], 'required' => ['topic']],
                'annotations' => $destructive,
            ],
            ...array_values(array_filter(
                $this->api->definitions(),
                fn (array $definition) => !str_starts_with($definition['name'], self::HIDDEN_PREFIX),
            )),
        ];
    }

    /**
     * @return array MCP CallToolResult; lookup failures (not found, ambiguous) are tool errors the model can relay
     *
     * @throws \InvalidArgumentException when the tool doesn't exist
     */
    public function call(string $name, array $arguments): array
    {
        try {
            $text = match ($name) {
                'delete_category' => $this->deleteCategory((string) ($arguments['name'] ?? '')),
                'delete_topic' => $this->deleteTopic((string) ($arguments['title'] ?? '')),
                'delete_post' => $this->deletePost((string) ($arguments['topic'] ?? ''), (string) ($arguments['author'] ?? ''), (string) ($arguments['excerpt'] ?? '')),
                default => null,
            };
        } catch (\UnexpectedValueException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }

        if (null !== $text) {
            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        }
        if (str_starts_with($name, self::HIDDEN_PREFIX)) {
            throw new \InvalidArgumentException(\sprintf('Unknown tool: %s', $name));
        }

        return $this->api->call($name, $arguments);
    }

    private function deleteCategory(string $name): string
    {
        $category = $this->pick($this->request('get_api_category_list'), $name, 'name', 'catégorie');
        $this->request('delete_api_category_delete', ['id' => $category['id']]);

        return \sprintf('Catégorie « %s » (id %d) supprimée, avec ses sujets et leurs messages.', $category['name'], $category['id']);
    }

    private function deleteTopic(string $title): string
    {
        $topic = $this->findTopic($title);
        $this->request('delete_api_topic_delete', ['id' => $topic['id']]);

        return \sprintf('Sujet « %s » (id %d) supprimé, avec ses messages.', $topic['title'], $topic['id']);
    }

    private function deletePost(string $topicTitle, string $author, string $excerpt): string
    {
        if ('' === trim($author) && '' === trim($excerpt)) {
            throw new \UnexpectedValueException('Indique l\'auteur et/ou un extrait du message à supprimer.');
        }

        $topic = $this->findTopic($topicTitle);
        $posts = array_values(array_filter(
            $this->request('get_api_topic_show', ['id' => $topic['id']])['posts'],
            fn (array $post) => ('' === trim($author) || mb_strtolower($post['author']) === mb_strtolower(trim($author)))
                && ('' === trim($excerpt) || false !== mb_stripos($post['content'], trim($excerpt))),
        ));

        if (1 !== \count($posts)) {
            throw new \UnexpectedValueException([] === $posts
                ? \sprintf('Aucun message correspondant dans le sujet « %s ».', $topic['title'])
                : \sprintf('Plusieurs messages correspondent dans le sujet « %s », précise l\'auteur ou l\'extrait : %s', $topic['title'], $this->describe($posts, fn (array $post) => \sprintf('%s le %s : « %s »', $post['author'], $post['createdAt'], mb_strimwidth($post['content'], 0, 60, '…')))));
        }

        $this->request('delete_api_post_delete', ['id' => $posts[0]['id']]);

        return \sprintf('Message de %s (id %d) supprimé du sujet « %s ».', $posts[0]['author'], $posts[0]['id'], $topic['title']);
    }

    // ponytail: one GET per category on each call; add a topic search route to the API if the forum grows.
    private function findTopic(string $title): array
    {
        $topics = [];
        foreach ($this->request('get_api_category_list') as $category) {
            foreach ($this->request('get_api_topic_list', ['id' => $category['id']]) as $topic) {
                $topics[] = $topic + ['category' => $category['name']];
            }
        }

        return $this->pick($topics, $title, 'title', 'sujet');
    }

    /**
     * Exact match (case-insensitive) first, then substring: exactly one item must match.
     */
    private function pick(array $items, string $search, string $field, string $kind): array
    {
        $search = trim($search);
        if ('' === $search) {
            throw new \UnexpectedValueException(\sprintf('Indique le %s à supprimer.', 'catégorie' === $kind ? 'nom de la catégorie' : 'titre du sujet'));
        }

        $exact = array_filter($items, fn (array $item) => mb_strtolower($item[$field]) === mb_strtolower($search));
        $matches = array_values($exact ?: array_filter($items, fn (array $item) => false !== mb_stripos($item[$field], $search)));
        if (1 === \count($matches)) {
            return $matches[0];
        }

        $label = fn (array $item) => '« '.$item[$field].' »'.(isset($item['category']) ? ' (catégorie '.$item['category'].')' : '');
        throw new \UnexpectedValueException([] === $matches
            ? \sprintf('Aucun(e) %s ne correspond à « %s ».', $kind, $search)
            : \sprintf('Plusieurs %ss correspondent à « %s », précise lequel : %s', $kind, $search, $this->describe($matches, $label)));
    }

    private function describe(array $items, callable $label): string
    {
        $shown = implode(' ; ', array_map($label, \array_slice($items, 0, self::MAX_CANDIDATES)));

        return \count($items) > self::MAX_CANDIDATES ? $shown.\sprintf(' ; et %d autres.', \count($items) - self::MAX_CANDIDATES) : $shown;
    }

    /**
     * Runs an API operation through its generated tool (an internal sub-request).
     *
     * @return array|null the decoded JSON body, null when empty (204)
     */
    private function request(string $tool, array $arguments = []): ?array
    {
        $result = $this->api->call($tool, array_map(fn ($value) => (string) $value, $arguments));
        $body = $result['content'][0]['text'];
        if ($result['isError']) {
            throw new \UnexpectedValueException(\sprintf('Erreur de l\'API (%s) : %s', $tool, $body));
        }

        return '' === $body ? null : json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
    }
}
