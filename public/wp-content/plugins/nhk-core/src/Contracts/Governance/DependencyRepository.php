<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface DependencyRepository
{
    /** @return list<string> */
    public function directDependencies(string $proposalId): array;
    public function add(string $proposalId, string $dependencyUuid): void;
}
