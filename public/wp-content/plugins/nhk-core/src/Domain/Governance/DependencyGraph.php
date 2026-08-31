<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

use NHK\Core\Contracts\Governance\DependencyRepository;
use NHK\Core\Governance\Exception\DependencyCycle;

final class DependencyGraph
{
    public function __construct(private DependencyRepository $repository) {}

    public function add(string $proposalId, string $dependencyUuid): void
    {
        if ($proposalId === $dependencyUuid) throw new DependencyCycle('A proposal cannot depend on itself.');
        if (in_array($proposalId, $this->closure($dependencyUuid), true)) throw new DependencyCycle('Dependency cycle detected.');
        $this->repository->add($proposalId, $dependencyUuid);
    }

    /** @return list<string> */
    public function closure(string $proposalId): array
    {
        $seen = []; $visit = function (string $id) use (&$visit, &$seen): void {
            foreach ($this->repository->directDependencies($id) as $dependency) {
                if (isset($seen[$dependency])) continue;
                $seen[$dependency] = true; $visit($dependency);
            }
        };
        $visit($proposalId);
        return array_keys($seen);
    }
}
