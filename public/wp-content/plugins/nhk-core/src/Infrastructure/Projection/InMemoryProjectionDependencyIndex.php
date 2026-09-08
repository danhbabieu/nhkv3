<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Projection;

use NHK\Core\Contracts\Projection\ProjectionDependencyIndex;

final class InMemoryProjectionDependencyIndex implements ProjectionDependencyIndex
{
    /** @var list<array<string,mixed>> */
    private array $items = [];

    public function status(): array { return ['status' => 'available', 'reason' => null, 'missing_tables' => []]; }

    public function add(array $dependency): void
    {
        $key = implode('|', [(string) ($dependency['kind'] ?? ''), (string) ($dependency['id'] ?? ''), (string) ($dependency['node_uuid'] ?? ''), (string) ($dependency['section_key'] ?? '')]);
        foreach ($this->items as $item) if (($item['_key'] ?? '') === $key) return;
        $dependency['_key'] = $key; $this->items[] = $dependency;
    }

    public function findByDependency(string $kind, string $id): array
    {
        return array_values(array_map(static function (array $item): array { unset($item['_key']); return $item; }, array_filter($this->items, static fn (array $item): bool => ($item['kind'] ?? '') === $kind && ($item['id'] ?? '') === $id)));
    }

    public function removeForNode(string $nodeUuid): void
    {
        $this->items = array_values(array_filter($this->items, static fn (array $item): bool => ($item['node_uuid'] ?? '') !== $nodeUuid));
    }
}
