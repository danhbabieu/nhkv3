<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};

final class AdminKnowledgeAdapter
{
    /** @param iterable<AuthorityEntity> $entities @param iterable<KnowledgeClaim> $claims @param iterable<Source> $sources @param iterable<Evidence> $evidence */
    public function __construct(private iterable $entities = [], private iterable $claims = [], private iterable $sources = [], private iterable $evidence = []) {}

    /** @return list<array<string,mixed>> */
    public function find(string $query, string $tab = 'entity'): array
    {
        $query = strtolower(trim($query)); $items = match ($tab) { 'claim' => $this->claims, 'source' => $this->sources, 'evidence' => $this->evidence, default => $this->entities };
        $rows = [];
        foreach ($items as $item) {
            if (!$this->matches($item, $query)) continue;
            $rows[] = $this->row($item, $tab);
        }
        return $rows;
    }

    private function matches(mixed $item, string $query): bool
    {
        if ($query === '') return true;
        $values = match (true) {
            $item instanceof AuthorityEntity => [$item->canonicalId, $item->stableKey, $item->canonicalName, $item->entityType],
            $item instanceof KnowledgeClaim => [$item->canonicalId, $item->stableKey, $item->claimText, $item->claimType],
            $item instanceof Source => [$item->canonicalId, $item->stableKey, $item->title, $item->sourceType, $item->locator],
            $item instanceof Evidence => [$item->canonicalId, $item->claimId, $item->sourceId, $item->excerpt, $item->locator],
            default => [],
        };
        return str_contains(strtolower(implode(' ', array_map(static fn (mixed $value): string => (string) $value, $values))), $query);
    }

    /** @return array<string,mixed> */
    private function row(mixed $item, string $tab): array
    {
        if ($item instanceof AuthorityEntity) return ['id' => $item->canonicalId, 'title' => $item->canonicalName, 'type' => $item->entityType, 'status' => $item->active() ? 'active' : 'retired', 'revision' => $item->revision];
        if ($item instanceof KnowledgeClaim) return ['id' => $item->canonicalId, 'title' => $item->claimText, 'type' => $item->claimType, 'status' => $item->active ? 'active' : 'retired', 'revision' => $item->revision];
        if ($item instanceof Source) return ['id' => $item->canonicalId, 'title' => $item->title, 'type' => $item->sourceType, 'status' => $item->active ? 'active' : 'retired', 'revision' => $item->revision];
        return ['id' => $item->canonicalId, 'title' => $item->excerpt, 'type' => $item->relation, 'status' => $item->active ? 'active' : 'retired', 'revision' => $item->revision];
    }
}
