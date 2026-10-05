<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface ProposalDiscoveryReader
{
    /** @return list<array<string,mixed>> */
    public function discover(array $selectors, int $limit): array;
}
