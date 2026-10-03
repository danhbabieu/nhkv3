<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Read-only, bounded public projection of lexical Mention attestations. */
final class DictionaryMentionPublicProjection
{
    /** @param callable(string,string):(?array<string,mixed>)|null $sourceResolver */
    public function __construct(private object $repository, private $sourceResolver = null) {}

    /** @return array<string,mixed> */
    public function forConcept(string $conceptId, int $limit = 50): array
    {
        if (!method_exists($this->repository, 'listByConcept')) return ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'groups' => []];
        $mentions = $this->repository->listByConcept($conceptId, max(1, min(51, $limit)), 0);
        $groups = [];
        foreach ((array) $mentions as $mention) {
            if (!is_object($mention)) continue;
            $kind = strtoupper(trim((string) ($mention->sourceKind ?? '')));
            $sourceId = trim((string) ($mention->sourceId ?? ''));
            if ($kind === '' || $sourceId === '' || !is_callable($this->sourceResolver)) continue;
            try { $source = ($this->sourceResolver)($kind, $sourceId); } catch (\Throwable) { $source = null; }
            if (!is_array($source) || trim((string) ($source['title'] ?? '')) === '') continue;
            $key = $kind . ':' . ($source['url'] ?? $source['title']);
            $groups[$kind][$key] = $source + ['source_kind' => $kind];
        }
        $groups = array_map(static fn (array $items): array => array_values($items), $groups);
        return ['status' => $groups === [] ? 'AVAILABLE_EMPTY' : 'AVAILABLE_WITH_ITEMS', 'groups' => $groups];
    }
}
