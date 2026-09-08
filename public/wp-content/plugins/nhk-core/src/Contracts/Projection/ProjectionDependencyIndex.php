<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Projection;

interface ProjectionDependencyIndex
{
    /** @param array<string,mixed> $dependency */
    public function add(array $dependency): void;
    /** @return list<array<string,mixed>> */
    public function findByDependency(string $kind, string $id): array;
    public function removeForNode(string $nodeUuid): void;
}
