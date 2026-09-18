<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface PendingVideoProposalLookup
{
    /** @param array<string,mixed> $binding @return list<Proposal> */
    public function findPendingVideoProposals(array $binding): array;
}
