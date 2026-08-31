<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface EligibilityReader
{
    public function isApplied(string $dependencyUuid): bool;
    public function targetRevision(string $targetUuid): ?int;
    public function targetExists(string $targetUuid): bool;
}
