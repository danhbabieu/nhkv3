<?php
declare(strict_types=1);

namespace NHK\Tests\Support;

use NHK\Core\Contracts\Governance\DependencyRepository;

final class InMemoryDependencyRepository implements DependencyRepository
{
    private array $items = [];
    public function directDependencies(string $proposalId): array { return $this->items[$proposalId] ?? []; }
    public function add(string $proposalId, string $dependencyUuid): void { $this->items[$proposalId][] = $dependencyUuid; }
}
